<?php

namespace Tests\Feature\Serials;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Services\SerialService;
use App\Services\StockService;
use App\Services\StockTakeService;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Counts and corrections move serials with the units.
 *
 * A count that found a unit missing took the number down and left its serial
 * "On the shelf", so the serial list offered a phone the stock figure said was
 * gone. Now the count names which serials left (Missing, or written off when
 * damaged), and a unit found names its serial — a Missing one comes back.
 */
class CountSerialsTest extends TestCase
{
    use RefreshDatabase;

    private Product $phone;

    private Store $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();

        $this->owner = User::factory()->create(['role' => 'admin']);
        $this->branch = Store::create([
            'name' => 'Khulna', 'city' => 'Khulna', 'address' => 'Test', 'phone' => '01711000000',
            'is_active' => true, 'holds_stock' => true, 'fulfils_online' => true,
        ]);
        $this->phone = Product::create([
            'category_id' => Category::firstOrCreate(['slug' => 'phone'], ['name' => 'Phone', 'is_active' => true])->id,
            'name' => 'Pixel', 'slug' => 'pixel-count', 'price' => 90000, 'stock_quantity' => 0,
            'is_active' => true, 'warranty_months' => 12,
        ]);

        app(StockService::class)->receive(['store_id' => $this->branch->id], [
            ['product_id' => $this->phone->id, 'quantity' => 2, 'unit_cost' => 80000],
        ]);
        app(SerialService::class)->receive($this->phone, null, ['PX-1', 'PX-2'], $this->branch->id);
    }

    private function countShelf(int $counted, array $extra = [])
    {
        return $this->actingAs($this->owner)->postJson('/api/admin/stock/count', [
            'store_id' => $this->branch->id,
            'lines' => [['product_id' => $this->phone->id, 'counted_quantity' => $counted] + $extra],
        ]);
    }

    private function shelf(): int
    {
        return (int) ProductStock::where('store_id', $this->branch->id)->where('product_id', $this->phone->id)->value('quantity');
    }

    private function serial(string $s): ProductSerial
    {
        return ProductSerial::where('serial', $s)->first();
    }

    public function test_the_count_sheet_lists_the_shelfs_serials(): void
    {
        $line = collect(app(StockTakeService::class)->sheetFor($this->branch))->firstWhere('product_id', $this->phone->id);

        $this->assertSame(['PX-1', 'PX-2'], collect($line['serials'])->pluck('serial')->all());
        $this->assertTrue($line['needs_serials']);
    }

    public function test_a_short_count_must_say_which_serial_is_gone(): void
    {
        $this->countShelf(1)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'tick which 1 serial number is no longer on the shelf'));

        // Nothing moved.
        $this->assertSame(2, $this->shelf());
        $this->assertSame(ProductSerial::IN_STOCK, $this->serial('PX-2')->status);
    }

    public function test_the_ticked_serial_becomes_missing(): void
    {
        $this->countShelf(1, ['missing_serial_ids' => [$this->serial('PX-2')->id]])->assertStatus(201);

        $this->assertSame(1, $this->shelf());
        $this->assertSame(ProductSerial::MISSING, $this->serial('PX-2')->status);
        $this->assertSame(ProductSerial::IN_STOCK, $this->serial('PX-1')->status);
    }

    public function test_a_missing_unit_found_at_the_next_count_comes_back_under_its_serial(): void
    {
        $this->countShelf(1, ['missing_serial_ids' => [$this->serial('PX-2')->id]])->assertStatus(201);

        // Found, but its serial not given: refused on a product with a warranty.
        $this->countShelf(2)->assertStatus(422);

        $this->countShelf(2, ['found_serials' => "px-2\n"])->assertStatus(201);

        $this->assertSame(2, $this->shelf());
        $this->assertSame(ProductSerial::IN_STOCK, $this->serial('PX-2')->status);
        $this->assertSame(2, ProductSerial::where('product_id', $this->phone->id)->count());
    }

    public function test_a_serial_held_elsewhere_cannot_be_found_here(): void
    {
        $this->countShelf(3, ['found_serials' => 'PX-1'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'PX-1 is already on record'));
    }

    public function test_a_damaged_unit_corrected_out_is_written_off(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admin/stock/adjust', [
            'product_id' => $this->phone->id, 'quantity' => -1, 'reason' => 'damaged',
            'store_id' => $this->branch->id, 'serial_ids' => [$this->serial('PX-1')->id],
        ])->assertOk();

        $this->assertSame(1, $this->shelf());
        $this->assertSame(ProductSerial::FAULTY, $this->serial('PX-1')->status);
    }

    public function test_a_correction_out_must_say_which_serial(): void
    {
        $this->actingAs($this->owner)->postJson('/api/admin/stock/adjust', [
            'product_id' => $this->phone->id, 'quantity' => -1, 'reason' => 'lost',
            'store_id' => $this->branch->id,
        ])->assertStatus(422);

        $this->assertSame(2, $this->shelf());
    }
}
