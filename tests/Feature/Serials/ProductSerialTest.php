<?php

namespace Tests\Feature\Serials;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Store;
use App\Models\User;
use App\Services\SerialService;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which physical unit went to which customer.
 *
 * The warranty form asked for a serial number and nothing recorded one, so a
 * claim could not be checked against anything: not whether the unit was bought
 * here, not when its cover started, not whether the same serial had been
 * claimed before.
 */
class ProductSerialTest extends TestCase
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
            'name' => 'RTX 4090', 'slug' => 'rtx-4090-serial', 'price' => 245000,
            'stock_quantity' => 0, 'is_active' => true, 'warranty_months' => 36,
        ]);
    }

    private function order(int $quantity = 1): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-SERIAL'.rand(100, 999),
            'session_id' => str_repeat('a', 40),
            'status' => 'pending', 'subtotal' => 245000, 'shipping_fee' => 0,
            'discount' => 0, 'total' => 245000,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01711000000', 'city' => 'Dhaka'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 245000, 'quantity' => $quantity, 'total' => 245000 * $quantity,
        ]);

        return $order->load('items.product');
    }

    // --- taking them in ---------------------------------------------------

    public function test_serials_arrive_with_a_delivery(): void
    {
        $result = $this->serials->receive($this->product, null, ['SN-1', 'SN-2'], $this->store->id);

        $this->assertSame(2, $result['added']);
        $this->assertSame(2, ProductSerial::available()->count());
        $this->assertSame($this->store->id, ProductSerial::first()->store_id);
    }

    /** A serial is a code, not a sentence. */
    public function test_serials_are_stored_in_one_form(): void
    {
        $this->serials->receive($this->product, null, ['  sn-abc-1 '], $this->store->id);

        $this->assertSame('SN-ABC-1', ProductSerial::sole()->serial);
        $this->assertNotNull($this->serials->lookup('sn-abc-1'));
    }

    /**
     * A serial already on the books is a typo or a supplier duplicate.
     *
     * Both are worth stopping at the door: the alternative is finding out
     * during a claim, with two customers holding a unit the shop cannot
     * account for.
     */
    public function test_a_serial_the_shop_already_has_is_refused(): void
    {
        $this->serials->receive($this->product, null, ['SN-1'], $this->store->id);
        $result = $this->serials->receive($this->product, null, ['SN-1', 'SN-2'], $this->store->id);

        $this->assertSame(1, $result['added']);
        $this->assertSame(['SN-1'], $result['skipped']);
        $this->assertSame(2, ProductSerial::count());
    }

    public function test_the_same_serial_twice_in_one_delivery_lands_once(): void
    {
        $result = $this->serials->receive($this->product, null, ['SN-1', 'SN-1'], $this->store->id);

        $this->assertSame(1, $result['added']);
    }

    public function test_blank_entries_are_ignored(): void
    {
        $result = $this->serials->receive($this->product, null, ['', '   ', 'SN-1'], $this->store->id);

        $this->assertSame(1, $result['added']);
    }

    // --- selling them -----------------------------------------------------

    public function test_selling_ties_the_oldest_unit_to_the_order(): void
    {
        $this->serials->receive($this->product, null, ['SN-1', 'SN-2', 'SN-3'], $this->store->id);
        $order = $this->order();

        $assigned = $this->serials->assignToOrder($order, $this->store->id);

        $this->assertSame(1, $assigned);
        // Oldest first: what a shop picks, and what stops stock ageing.
        $sold = ProductSerial::where('status', ProductSerial::SOLD)->sole();
        $this->assertSame('SN-1', $sold->serial);
        $this->assertSame($order->id, $sold->order_id);
        $this->assertSame(2, ProductSerial::available()->count());
    }

    public function test_the_warranty_runs_from_the_day_it_went_out(): void
    {
        $this->serials->receive($this->product, null, ['SN-1'], $this->store->id);
        $this->serials->assignToOrder($this->order(), $this->store->id);

        $sold = ProductSerial::sole();

        $this->assertSame(now()->addMonths(36)->toDateString(), $sold->warranty_until->toDateString());
        $this->assertTrue($sold->under_warranty);
    }

    public function test_a_product_with_no_stated_cover_gets_no_expiry(): void
    {
        $this->product->update(['warranty_months' => null]);
        $this->serials->receive($this->product, null, ['SN-1'], $this->store->id);
        $this->serials->assignToOrder($this->order(), $this->store->id);

        $this->assertNull(ProductSerial::sole()->warranty_until);
        $this->assertNull(ProductSerial::sole()->under_warranty);
    }

    public function test_more_are_assigned_when_more_were_bought(): void
    {
        $this->serials->receive($this->product, null, ['SN-1', 'SN-2', 'SN-3'], $this->store->id);

        $this->assertSame(2, $this->serials->assignToOrder($this->order(2), $this->store->id));
    }

    /** Most stock has no serials, and selling it must not fall over. */
    public function test_selling_something_untracked_assigns_nothing(): void
    {
        $this->assertSame(0, $this->serials->assignToOrder($this->order(), $this->store->id));
    }

    /** Fewer serials on file than units sold is not a reason to refuse a sale. */
    public function test_a_short_shelf_assigns_what_there_is(): void
    {
        $this->serials->receive($this->product, null, ['SN-1'], $this->store->id);

        $this->assertSame(1, $this->serials->assignToOrder($this->order(3), $this->store->id));
    }

    public function test_assigning_twice_does_not_hand_over_a_second_unit(): void
    {
        $this->serials->receive($this->product, null, ['SN-1', 'SN-2'], $this->store->id);
        $order = $this->order();

        $this->serials->assignToOrder($order, $this->store->id);
        $this->assertSame(0, $this->serials->assignToOrder($order->fresh('items'), $this->store->id));
        $this->assertSame(1, ProductSerial::available()->count());
    }

    // --- taking them back -------------------------------------------------

    public function test_a_returned_order_gives_the_units_back(): void
    {
        $this->serials->receive($this->product, null, ['SN-1'], $this->store->id);
        $order = $this->order();
        $this->serials->assignToOrder($order, $this->store->id);

        $this->serials->returnFromOrder($order);

        $unit = ProductSerial::sole();
        $this->assertSame(ProductSerial::RETURNED, $unit->status);
        // The cover ended with the sale it was attached to.
        $this->assertNull($unit->warranty_until);
        $this->assertNull($unit->under_warranty);
    }

    public function test_a_unit_with_a_customer_cannot_be_written_off(): void
    {
        $this->serials->receive($this->product, null, ['SN-1'], $this->store->id);
        $this->serials->assignToOrder($this->order(), $this->store->id);

        $this->expectExceptionMessage('Take it back on the order first');
        $this->serials->writeOff(ProductSerial::sole());
    }

    // --- looking one up ---------------------------------------------------

    public function test_the_warranty_check_answers_from_the_serial(): void
    {
        $this->serials->receive($this->product, null, ['SN-1'], $this->store->id);
        $this->serials->assignToOrder($this->order(), $this->store->id);

        $this->getJson('/api/warranty/check?query=sn-1')
            ->assertSuccessful()
            ->assertJsonPath('data.serial_number', 'SN-1')
            ->assertJsonPath('data.is_under_warranty', true)
            ->assertJsonPath('data.warranty_period', '36 months from the date of sale');
    }

    /**
     * A unit still on the shelf is not a customer's to claim on.
     *
     * Said plainly rather than reported as expired, which would send somebody
     * arguing about a warranty that has not started.
     */
    public function test_a_unit_still_on_the_shelf_says_so(): void
    {
        $this->serials->receive($this->product, null, ['SN-1'], $this->store->id);

        $this->getJson('/api/warranty/check?query=SN-1')
            ->assertSuccessful()
            ->assertJsonPath('data.not_yet_sold', true)
            ->assertJsonPath('data.is_under_warranty', false);
    }

    public function test_an_unknown_serial_still_says_not_found(): void
    {
        $this->getJson('/api/warranty/check?query=SN-NOT-OURS')->assertStatus(404);
    }

    // --- the screen -------------------------------------------------------

    public function test_the_serials_screen_belongs_to_stock(): void
    {
        $keeper = User::factory()->create(['role' => Roles::STOREKEEPER, 'is_active' => true]);
        $support = User::factory()->create(['role' => Roles::SUPPORT, 'is_active' => true]);

        $this->actingAs($keeper)->get('/admin/stock/serials')->assertStatus(200);
        $this->actingAs($support)->get('/admin/stock/serials')->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_keeper_sees_only_their_own_branch(): void
    {
        $other = Store::create([
            'name' => 'Agrabad', 'city' => 'Chattogram', 'address' => 'Test',
            'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true,
        ]);

        $this->serials->receive($this->product, null, ['SN-MINE'], $this->store->id);
        $this->serials->receive($this->product, null, ['SN-THEIRS'], $other->id);

        $keeper = User::factory()->create([
            'role' => Roles::STOREKEEPER, 'is_active' => true, 'store_id' => $this->store->id,
        ]);

        $rows = $this->actingAs($keeper)->get('/admin/stock/serials')
            ->viewData('page')['props']['serials']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('SN-MINE', $rows[0]['serial']);
    }
}
