<?php

namespace Tests\Feature\Serials;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use App\Services\SerialService;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Getting a serial onto the books, and getting a wrong one off them.
 *
 * Serials could only ever enter with a delivery and could never be changed at
 * all. That left a shop starting to record them part way through with no way
 * to write down the shelf it already had, and left a number typed wrong wrong
 * for the life of the unit — which surfaces years later, at the counter, when
 * a warranty claim does not match the invoice.
 */
class SerialCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private SerialService $serials;

    private Product $product;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();

        $this->serials = app(SerialService::class);
        $this->store = Store::create([
            'name' => 'Uttara', 'city' => 'Dhaka', 'address' => 'Test',
            'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true,
        ]);
        $this->product = Product::create([
            'category_id' => Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'GPU', 'is_active' => true])->id,
            'name' => 'RTX 4090', 'slug' => 'rtx-4090-fix', 'price' => 245000,
            'stock_quantity' => 0, 'is_active' => true, 'warranty_months' => 36,
        ]);
    }

    private function onShelf(int $quantity): void
    {
        ProductStock::updateOrCreate(
            ['product_id' => $this->product->id, 'product_variant_id' => null, 'store_id' => $this->store->id],
            ['quantity' => $quantity]
        );
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // --- adding to what is already on the shelf ---------------------------

    public function test_serials_can_be_added_to_stock_received_without_them(): void
    {
        $this->onShelf(3);

        $result = $this->serials->addToStock($this->product, null, ['SN-A', 'SN-B'], $this->store->id);

        $this->assertSame(2, $result['added']);
        $this->assertSame(2, ProductSerial::available()->count());
        $this->assertSame($this->store->id, ProductSerial::first()->store_id);
    }

    public function test_a_serial_already_on_the_books_is_reported_not_duplicated(): void
    {
        $this->onShelf(5);
        $this->serials->addToStock($this->product, null, ['SN-A'], $this->store->id);

        $result = $this->serials->addToStock($this->product, null, ['SN-A', 'SN-B'], $this->store->id);

        $this->assertSame(1, $result['added']);
        $this->assertSame(['SN-A'], $result['skipped']);
        $this->assertSame(2, ProductSerial::count());
    }

    /**
     * The guard that keeps the two counts honest.
     *
     * Recording more serials than there are units means the serial list and the
     * stock figure disagree, and once they disagree neither can be trusted to
     * settle a warranty argument.
     */
    public function test_more_serials_than_units_is_refused(): void
    {
        $this->onShelf(2);

        $this->expectExceptionMessage('Only 2 of the 2 in stock still need a serial, and 3 were entered.');
        $this->serials->addToStock($this->product, null, ['SN-A', 'SN-B', 'SN-C'], $this->store->id);
    }

    public function test_a_fully_serialised_shelf_says_so(): void
    {
        $this->onShelf(1);
        $this->serials->addToStock($this->product, null, ['SN-A'], $this->store->id);

        $this->expectExceptionMessage('Every one of the 1 in stock already has a serial recorded.');
        $this->serials->addToStock($this->product, null, ['SN-B'], $this->store->id);
    }

    /** A sold unit's serial no longer occupies a space on the shelf. */
    public function test_a_sold_unit_frees_its_place_for_a_new_serial(): void
    {
        $this->onShelf(1);
        $this->serials->addToStock($this->product, null, ['SN-A'], $this->store->id);

        ProductSerial::first()->forceFill(['status' => ProductSerial::SOLD, 'sold_at' => now()])->save();

        $result = $this->serials->addToStock($this->product, null, ['SN-B'], $this->store->id);

        $this->assertSame(1, $result['added']);
    }

    public function test_nothing_typed_is_refused_rather_than_silently_accepted(): void
    {
        $this->onShelf(5);

        $this->expectExceptionMessage('Enter at least one serial number.');
        $this->serials->addToStock($this->product, null, ['   ', ''], $this->store->id);
    }

    // --- correcting one that is wrong -------------------------------------

    public function test_a_mistyped_serial_can_be_corrected(): void
    {
        $this->onShelf(1);
        $this->serials->addToStock($this->product, null, ['SN-WRNG'], $this->store->id);

        $serial = ProductSerial::first();
        $this->serials->correct($serial, 'sn-right', 'Read from the sticker.');

        $this->assertSame('SN-RIGHT', $serial->fresh()->serial);
        // The old number stays in writing, or a claim argued later has nothing
        // to point at.
        $this->assertStringContainsString('Corrected from SN-WRNG', $serial->fresh()->note);
        $this->assertStringContainsString('Read from the sticker.', $serial->fresh()->note);
    }

    /**
     * The case the whole thing exists for: the mismatch is noticed at the
     * counter, years after the sale.
     */
    public function test_a_sold_units_serial_can_still_be_corrected_without_touching_the_sale(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-FIX1',
            'session_id' => str_repeat('a', 40),
            'status' => 'delivered', 'subtotal' => 245000, 'shipping_fee' => 0,
            'discount' => 0, 'total' => 245000,
            'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01711000000', 'city' => 'Dhaka'],
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 245000, 'quantity' => 1, 'total' => 245000,
        ]);

        $serial = ProductSerial::create([
            'product_id' => $this->product->id, 'serial' => 'SN-TYPO',
            'store_id' => $this->store->id, 'status' => ProductSerial::SOLD,
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'sold_at' => now()->subYear(), 'warranty_until' => now()->addYears(2)->toDateString(),
        ]);

        $this->serials->correct($serial, 'SN-REAL');
        $serial->refresh();

        $this->assertSame('SN-REAL', $serial->serial);
        // Everything the cover rests on is untouched.
        $this->assertSame($order->id, $serial->order_id);
        $this->assertSame($item->id, $serial->order_item_id);
        $this->assertNotNull($serial->sold_at);
        $this->assertTrue($serial->under_warranty);
    }

    public function test_correcting_onto_a_number_that_exists_is_refused(): void
    {
        $this->onShelf(2);
        $this->serials->addToStock($this->product, null, ['SN-A', 'SN-B'], $this->store->id);

        $this->expectExceptionMessage('Serial SN-B is already recorded against another unit.');
        $this->serials->correct(ProductSerial::where('serial', 'SN-A')->first(), 'SN-B');
    }

    public function test_correcting_to_the_same_number_says_so(): void
    {
        $this->onShelf(1);
        $this->serials->addToStock($this->product, null, ['SN-A'], $this->store->id);

        $this->expectExceptionMessage('That is the number already recorded.');
        $this->serials->correct(ProductSerial::first(), 'sn-a');
    }

    // --- removing one recorded in error -----------------------------------

    public function test_a_serial_recorded_in_error_can_be_deleted(): void
    {
        $this->onShelf(1);
        $this->serials->addToStock($this->product, null, ['SN-A'], $this->store->id);

        $this->serials->remove(ProductSerial::first());

        $this->assertSame(0, ProductSerial::count());
    }

    /**
     * A sold unit's serial belongs to that sale and to any warranty resting on
     * it. Deleting it would quietly remove the shop's own evidence.
     */
    public function test_a_sold_units_serial_cannot_be_deleted(): void
    {
        $serial = ProductSerial::create([
            'product_id' => $this->product->id, 'serial' => 'SN-SOLD',
            'store_id' => $this->store->id, 'status' => ProductSerial::SOLD,
            'sold_at' => now(),
        ]);

        $this->expectExceptionMessage('That unit has been sold. Correct the number instead');
        $this->serials->remove($serial);
    }

    // --- through the endpoints --------------------------------------------

    public function test_the_endpoints_add_correct_and_remove(): void
    {
        $this->onShelf(2);
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/admin/stock/serials', [
            'product_id' => $this->product->id,
            'store_id' => $this->store->id,
            'serials' => ['SN-A', 'SN-B'],
        ])->assertOk();

        $this->assertSame(2, ProductSerial::count());

        $a = ProductSerial::where('serial', 'SN-A')->first();

        $this->actingAs($owner)->putJson("/api/admin/stock/serials/{$a->id}", [
            'serial' => 'SN-A-FIXED',
        ])->assertOk();

        $this->assertSame('SN-A-FIXED', $a->fresh()->serial);

        $this->actingAs($owner)->deleteJson("/api/admin/stock/serials/{$a->id}")->assertOk();

        $this->assertNull(ProductSerial::find($a->id));
    }

    /** A storekeeper confined to one branch cannot touch another's units. */
    public function test_a_branch_cannot_edit_another_branchs_serials(): void
    {
        $other = Store::create([
            'name' => 'Mirpur', 'city' => 'Dhaka', 'address' => 'Test',
            'phone' => '01711000001', 'is_active' => true, 'holds_stock' => true,
        ]);

        $serial = ProductSerial::create([
            'product_id' => $this->product->id, 'serial' => 'SN-THEIRS',
            'store_id' => $other->id, 'status' => ProductSerial::IN_STOCK,
        ]);

        $keeper = User::factory()->create(['role' => 'storekeeper', 'store_id' => $this->store->id]);

        $this->actingAs($keeper)
            ->putJson("/api/admin/stock/serials/{$serial->id}", ['serial' => 'SN-MINE'])
            ->assertStatus(403);

        $this->actingAs($keeper)
            ->deleteJson("/api/admin/stock/serials/{$serial->id}")
            ->assertStatus(403);

        $this->assertSame('SN-THEIRS', $serial->fresh()->serial);
    }
}
