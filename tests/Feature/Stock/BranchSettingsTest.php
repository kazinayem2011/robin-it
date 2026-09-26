<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The primary branch for online sales, on the Branches page.
 *
 * It could only be set in the database, so the shop could not say which
 * branch its online orders take stock from first.
 */
class BranchSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);
    }

    private function branch(string $name, array $over = []): Store
    {
        return Store::create($over + [
            'name' => $name, 'branch_type' => 'Showroom', 'city' => 'Dhaka', 'address' => 'Road 1',
            'phone' => '01711000000', 'opening_hours' => '10-8', 'is_active' => true,
            'holds_stock' => true, 'fulfils_online' => false, 'sort_order' => 1,
        ]);
    }

    private function save(Store $store, array $changes)
    {
        return $this->actingAs($this->admin)->putJson("/api/admin/stores/{$store->id}", $changes + [
            'name' => $store->name, 'branch_type' => $store->branch_type, 'city' => $store->city,
            'address' => $store->address, 'phone' => $store->phone, 'opening_hours' => $store->opening_hours,
            'is_active' => (bool) $store->is_active,
        ]);
    }

    private function stockAt(Store $store, int $units): void
    {
        $category = Category::firstOrCreate(['slug' => 'laptop'], ['name' => 'Laptop', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Laptop', 'slug' => 'laptop-'.uniqid(),
            'price' => 1000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        app(StockService::class)->record($product, null, $units, StockMovement::PURCHASE, ['store_id' => $store->id]);
    }

    public function test_one_branch_is_the_default_at_a_time(): void
    {
        $khulna = $this->branch('Khulna', ['fulfils_online' => true]);
        $dhaka = $this->branch('Dhaka');

        $this->save($dhaka, ['fulfils_online' => true, 'holds_stock' => true])->assertOk();

        $this->assertTrue($dhaka->fresh()->fulfils_online);
        $this->assertFalse($khulna->fresh()->fulfils_online);
        $this->assertSame($dhaka->id, Store::onlineFulfilment()->id);
    }

    /* Online orders take from it first, so it is open. */
    public function test_the_primary_branch_is_open(): void
    {
        $dhaka = $this->branch('Dhaka', ['is_active' => false]);

        $this->save($dhaka, ['fulfils_online' => true, 'is_active' => false])->assertOk();

        $this->assertTrue($dhaka->fresh()->fulfils_online);
        $this->assertTrue($dhaka->fresh()->is_active);
    }

    /* Every branch holds stock; the page does not offer to change that. */
    public function test_holding_stock_is_not_something_the_form_changes(): void
    {
        $dhaka = $this->branch('Dhaka');

        $this->save($dhaka, ['holds_stock' => false])->assertOk();

        $this->assertTrue($dhaka->fresh()->holds_stock);
    }

    public function test_a_branch_with_stock_cannot_be_closed_or_removed(): void
    {
        $dhaka = $this->branch('Dhaka');
        $this->stockAt($dhaka, 2);

        $this->save($dhaka, ['is_active' => false])->assertStatus(422);

        $this->actingAs($this->admin)->deleteJson("/api/admin/stores/{$dhaka->id}")->assertStatus(422);
        $this->assertNotNull($dhaka->fresh());
    }

    public function test_the_page_lists_the_default_first(): void
    {
        $this->branch('Khulna');
        $this->branch('Dhaka', ['fulfils_online' => true, 'sort_order' => 5]);

        $this->actingAs($this->admin)->get('/admin/stores')
            ->assertInertia(fn ($page) => $page
                ->where('stores.0.name', 'Dhaka')
                ->where('stores.0.fulfils_online', true));
    }
}
