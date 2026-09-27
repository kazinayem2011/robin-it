<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Serial numbers go with the boxes when stock moves between branches.
 *
 * A transfer moved the count and left every serial recorded at the old
 * branch, so the new one could not sell them against a serial and the Serial
 * numbers tab showed them in the wrong place.
 */
class SerialTransferTest extends TestCase
{
    use RefreshDatabase;

    private Store $uttara;

    private Store $khulna;

    private User $admin;

    private Product $laptop;

    /** @var array<int, ProductSerial> */
    private array $serials = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->uttara = $this->branch('Uttara', 1);
        $this->khulna = $this->branch('Khulna', 2);
        $this->admin = User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);

        $category = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $this->laptop = Product::create([
            'category_id' => $category->id, 'name' => 'ASUS Vivobook', 'slug' => 'asus-vivobook',
            'price' => 70000, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        app(StockService::class)->record($this->laptop, null, 3, StockMovement::PURCHASE, ['store_id' => $this->uttara->id]);

        foreach (['SN-A', 'SN-B', 'SN-C'] as $serial) {
            $this->serials[$serial] = ProductSerial::create([
                'product_id' => $this->laptop->id, 'serial' => $serial,
                'store_id' => $this->uttara->id, 'status' => ProductSerial::IN_STOCK,
            ]);
        }
    }

    private function branch(string $name, int $order): Store
    {
        return Store::create([
            'name' => $name, 'city' => $name, 'address' => 'Test address', 'phone' => '01711000000',
            'is_active' => true, 'holds_stock' => true, 'sort_order' => $order,
        ]);
    }

    private function transfer(int $quantity, array $serials)
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/stock/transfer', [
            'product_id' => $this->laptop->id,
            'quantity' => $quantity,
            'from_store_id' => $this->uttara->id,
            'to_store_id' => $this->khulna->id,
            'serials' => array_map(fn ($s) => $this->serials[$s]->id, $serials),
        ]);
    }

    public function test_the_ticked_serials_move_with_the_units(): void
    {
        $this->transfer(2, ['SN-A', 'SN-C'])->assertOk();

        $this->assertSame($this->khulna->id, $this->serials['SN-A']->fresh()->store_id);
        $this->assertSame($this->khulna->id, $this->serials['SN-C']->fresh()->store_id);
        $this->assertSame($this->uttara->id, $this->serials['SN-B']->fresh()->store_id, 'the one left behind stays');
    }

    public function test_the_serials_must_match_how_many_are_moving(): void
    {
        $this->transfer(2, ['SN-A'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tick the 2 serial numbers of the units you are moving.');

        // Nothing moved: not the count, not the serials.
        $this->assertSame($this->uttara->id, $this->serials['SN-A']->fresh()->store_id);
        $this->assertSame(0, StockMovement::where('type', StockMovement::TRANSFER)->count());
    }

    public function test_a_serial_from_another_branch_is_refused(): void
    {
        $this->serials['SN-B']->update(['store_id' => $this->khulna->id]);

        $this->transfer(1, ['SN-B'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'not in stock at that branch'));
    }

    public function test_the_branch_list_offers_each_branchs_serials(): void
    {
        $rows = $this->actingAs($this->admin)
            ->getJson("/api/admin/stock/products/{$this->laptop->id}/branches")
            ->assertOk()
            ->json('data');

        $uttara = collect($rows)->firstWhere('store_id', $this->uttara->id);
        $this->assertSame(['SN-A', 'SN-B', 'SN-C'], array_column($uttara['serials'], 'serial'));
    }

    /* A product with no serials on the books moves as before. */
    public function test_a_product_without_serials_needs_none(): void
    {
        ProductSerial::query()->delete();

        $this->transfer(2, [])->assertOk();
    }
}
