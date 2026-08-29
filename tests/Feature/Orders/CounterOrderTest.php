<?php

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Taking an order at the counter or over the phone.
 *
 * The shop could only receive an order through the storefront, so a customer
 * ringing up could not be served without being asked to go home and use the
 * website — while every other part of the app assumes an order exists: stock,
 * serials, payments, delivery, the margin report.
 *
 * The point of these tests is not that an order appears. It is that a counter
 * order goes through the same OrderService as a storefront one, so the stock
 * check, the reservation and the coupon rules cannot drift apart.
 */
class CounterOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $gpu;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->staff = User::factory()->create(['role' => 'admin']);

        $this->gpu = Product::create([
            'category_id' => Category::create(['name' => 'GPU', 'slug' => 'gpu', 'is_active' => true])->id,
            'name' => 'RTX 4090', 'slug' => 'rtx-4090-counter',
            'price' => 10000, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        app(StockService::class)->receive([], [[
            'product_id' => $this->gpu->id, 'quantity' => 10, 'unit_cost' => 7000,
        ]]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahim Uddin',
            'phone' => '01712345678',
            'street_address' => 'House 45, Road 3',
            'city' => 'Dhaka',
            'lines' => [['product_id' => $this->gpu->id, 'quantity' => 2]],
        ], $overrides);
    }

    // --- a walk-in ------------------------------------------------------------

    public function test_staff_can_take_an_order_for_somebody_with_no_account(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload())
            ->assertOk();

        $order = Order::latest('id')->first();

        $this->assertNull($order->user_id, 'A walk-in has no account.');
        $this->assertSame('Rahim Uddin', $order->shipping_address['name']);
        $this->assertSame('01712345678', $order->shipping_address['phone']);
        $this->assertSame(2, $order->items->sum('quantity'));
        $this->assertSame(20000.0, (float) $order->subtotal);
    }

    public function test_an_order_can_be_attached_to_a_registered_customer(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);

        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload(['user_id' => $customer->id]))
            ->assertOk();

        $order = Order::latest('id')->first();

        $this->assertSame($customer->id, $order->user_id);
        // And it shows up in their own account, like any other order.
        $this->assertSame(1, $customer->orders()->count());
    }

    // --- the same rules as the storefront -------------------------------------

    /** The counter sells off the same shelf, so it reserves off it too. */
    public function test_it_reserves_stock_exactly_as_a_storefront_order_does(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload())
            ->assertOk();

        $this->assertSame(8, $this->gpu->fresh()->stock_quantity);
    }

    /**
     * The rule that matters most: a counter sale cannot promise units the shop
     * does not hold, for the same reason a website order cannot.
     */
    public function test_it_cannot_sell_stock_the_shop_does_not_have(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload([
                'lines' => [['product_id' => $this->gpu->id, 'quantity' => 25]],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->gpu->fresh()->stock_quantity, 'A refused order must move nothing.');
    }

    public function test_a_delisted_product_cannot_be_sold_at_the_counter_either(): void
    {
        $this->gpu->update(['is_active' => false]);

        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload())
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    /** A coupon is redeemed through the same lock and count as at checkout. */
    public function test_a_coupon_applies_and_is_counted(): void
    {
        $coupon = Coupon::create([
            'code' => 'EID15', 'discount_type' => 'percent', 'discount_value' => 10,
            'is_active' => true, 'usage_limit' => 1,
        ]);

        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload(['coupon_code' => 'EID15']))
            ->assertOk();

        $order = Order::latest('id')->first();

        $this->assertSame(2000.0, (float) $order->discount);
        $this->assertSame('EID15', $order->coupon_code);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_a_code_that_does_not_exist_is_refused_by_name(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload(['coupon_code' => 'NOPE']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'COUPON_INVALID');
    }

    /** Money is recorded when it is taken, the same as any other order. */
    public function test_the_order_starts_unpaid(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload())
            ->assertOk();

        $order = Order::latest('id')->first();

        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame((float) $order->total, $order->amount_due);
    }

    // --- the scratch cart -----------------------------------------------------

    /**
     * The order is built in a cart of its own, never the customer's.
     *
     * placeOrder empties the cart it is given, so writing into the one the
     * customer already has would clear the basket they had been building on
     * the website while ringing up to buy something else.
     */
    public function test_it_never_touches_the_customers_own_basket(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);

        $theirs = Cart::create(['user_id' => $customer->id, 'session_id' => str_repeat('c', 40)]);
        CartItem::create([
            'cart_id' => $theirs->id, 'product_id' => $this->gpu->id, 'quantity' => 3,
        ]);

        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload(['user_id' => $customer->id]))
            ->assertOk();

        $this->assertSame(3, $theirs->fresh()->items()->sum('quantity'), 'Their basket is untouched.');
    }

    /** A refused order must not leave its scratch cart behind. */
    public function test_a_refused_order_leaves_no_cart_lying_around(): void
    {
        $before = Cart::count();

        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload([
                'lines' => [['product_id' => $this->gpu->id, 'quantity' => 999]],
            ]))
            ->assertStatus(422);

        $this->assertSame($before, Cart::count());
        $this->assertSame(0, CartItem::count());
    }

    // --- who may do it ---------------------------------------------------------

    public function test_taking_an_order_needs_the_orders_ability(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/api/admin/orders', $this->payload())
            ->assertStatus(403);
    }

    public function test_a_bad_phone_number_is_refused(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/admin/orders', $this->payload(['phone' => '12345']))
            ->assertStatus(422);
    }

    // --- finding the customer ---------------------------------------------------

    public function test_customers_can_be_searched_by_name_number_or_email(): void
    {
        User::factory()->create([
            'role' => 'customer', 'name' => 'Rahim Uddin',
            'email' => 'rahim@example.com', 'phone' => '01712345678', 'is_active' => true,
        ]);

        foreach (['Rahim', '01712', 'rahim@'] as $term) {
            $found = $this->actingAs($this->staff)
                ->getJson('/api/admin/orders/customers?search='.urlencode($term))
                ->assertOk()
                ->json('data');

            $this->assertCount(1, $found, "Searching \"{$term}\" should have found them.");
        }
    }

    /**
     * A suspended customer cannot order for themselves, so staff must not be
     * able to order on their behalf either — that would be a way round the
     * suspension rather than a convenience.
     */
    public function test_a_suspended_customer_is_not_offered(): void
    {
        User::factory()->create([
            'role' => 'customer', 'name' => 'Barred Person',
            'email' => 'barred@example.com', 'is_active' => false,
        ]);

        $this->actingAs($this->staff)
            ->getJson('/api/admin/orders/customers?search=Barred')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_one_letter_search_returns_nothing_rather_than_everyone(): void
    {
        User::factory()->count(3)->create(['role' => 'customer', 'is_active' => true]);

        $this->actingAs($this->staff)
            ->getJson('/api/admin/orders/customers?search=a')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // --- and it really is the same code -----------------------------------------

    /**
     * The guarantee this whole feature rests on: one path, not two.
     *
     * If somebody later writes a second order-creation routine for the counter,
     * the stock check and the coupon lock become two copies to keep in step and
     * nothing will notice they have drifted until a counter sale oversells.
     */
    public function test_the_counter_goes_through_the_storefront_service(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Admin/OrderController.php'));

        $this->assertStringContainsString('placeForCustomer', $source);
        $this->assertStringNotContainsString('Order::create(', $source,
            'The admin must not write orders itself; it goes through OrderService.');

        $service = file_get_contents(base_path('app/Services/OrderService.php'));
        $this->assertStringContainsString('return $this->placeOrder(', $service,
            'placeForCustomer has to delegate rather than reimplement.');
    }
}
