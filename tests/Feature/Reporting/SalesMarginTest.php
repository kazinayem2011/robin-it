<?php

namespace Tests\Feature\Reporting;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use App\Support\SalesMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Goods sold less what those goods cost.
 *
 * Not a profit-and-loss statement, and the tests say so: there are no expense
 * records yet, and delivery is excluded on both sides because paying the
 * courier is one of the expenses nothing records.
 */
class SalesMarginTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);
    }

    private function stocked(string $slug, float $price, ?float $cost, int $qty = 20): Product
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'price' => $price,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->stock->receive([], [[
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_cost' => $cost,
        ]]);

        return $product;
    }

    private function buy(Product $product, int $quantity = 1, ?string $coupon = null): Order
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $product->id,
            'quantity' => $quantity,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', array_filter([
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
            'coupon_code' => $coupon,
        ]))->assertStatus(201);

        return Order::latest('id')->first();
    }

    public function test_nothing_sold_reports_nothing(): void
    {
        $summary = SalesMargin::summary();

        $this->assertSame(0.0, $summary['gross_profit']);
        $this->assertSame(0, $summary['orders_counted']);
        $this->assertNull($summary['margin_percent']);
    }

    public function test_it_reports_goods_sold_less_what_they_cost(): void
    {
        $this->buy($this->stocked('ryzen', 20000, 14000), 2);

        $summary = SalesMargin::summary();

        $this->assertSame(40000.0, $summary['goods_revenue']);
        $this->assertSame(28000.0, $summary['cost']);
        $this->assertSame(12000.0, $summary['gross_profit']);
        $this->assertSame(30.0, $summary['margin_percent']);
        $this->assertSame(1, $summary['orders_counted']);
    }

    /** The courier's fee is not the shop's income. */
    public function test_the_delivery_fee_is_left_out(): void
    {
        $order = $this->buy($this->stocked('ryzen', 20000, 14000), 1);

        $this->assertGreaterThan(0, (float) $order->shipping_fee);
        $this->assertSame(6000.0, SalesMargin::summary()['gross_profit']);
    }

    /** An order counted at a partial cost reads as profit that is not there. */
    public function test_an_order_with_an_uncosted_line_is_excluded_and_counted(): void
    {
        $this->buy($this->stocked('costed', 20000, 14000), 1);
        $this->buy($this->stocked('mystery', 9000, null), 1);

        $summary = SalesMargin::summary();

        $this->assertSame(6000.0, $summary['gross_profit'], 'The uncosted order leaked in.');
        $this->assertSame(1, $summary['orders_counted']);
        $this->assertSame(1, $summary['orders_uncosted']);
    }

    public function test_a_cancelled_order_does_not_count(): void
    {
        $order = $this->buy($this->stocked('ryzen', 20000, 14000), 1);

        $this->assertSame(6000.0, SalesMargin::summary()['gross_profit']);

        app(OrderService::class)->updateOrderStatus($order, 'cancelled');

        $this->assertSame(0.0, SalesMargin::summary()['gross_profit']);
        $this->assertSame(0, SalesMargin::summary()['orders_counted']);
    }

    /** A discount comes out of the shop's margin, not the customer's. */
    public function test_a_coupon_reduces_the_margin(): void
    {
        $product = $this->stocked('ryzen', 20000, 14000);

        Coupon::create([
            'code' => 'SAVE2K',
            'discount_type' => 'fixed',
            'discount_value' => 2000,
            'is_active' => true,
        ]);

        $this->buy($product, 1, 'SAVE2K');

        $summary = SalesMargin::summary();

        $this->assertSame(18000.0, $summary['goods_revenue']);   // 20000 less the 2000 code
        $this->assertSame(4000.0, $summary['gross_profit']);
    }

    /**
     * Every live order lands on one side of the line or the other.
     *
     * The warning under the figures says how much is not counted, which is
     * only true if "counted" and "not counted" between them cover everything.
     * They did not: an order with no lines at all was dropped by the inner
     * join that builds the costed set, and dropped again by the check for a
     * line without a cost — so it was missing from the figure and missing from
     * the notice about the figure.
     */
    public function test_no_order_falls_between_counted_and_excluded(): void
    {
        // One properly costed, one with a line nobody costed, and one with no
        // lines at all — the shape that used to vanish.
        $this->buy($this->stocked('costed', 20000, 14000), 1);
        $this->buy($this->stocked('uncosted', 30000, null), 1);

        Order::create([
            'order_number' => 'ORD-EMPTY',
            'session_id' => str_repeat('e', 40),
            'status' => 'processing',
            'subtotal' => 245000, 'shipping_fee' => 0, 'discount' => 0, 'total' => 245000,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);

        $summary = SalesMargin::summary();

        $live = Order::whereNotIn('status', ['cancelled', 'returned'])->count();

        $this->assertSame(3, $live);
        $this->assertSame(
            $live,
            $summary['orders_counted'] + $summary['orders_uncosted'],
            'Some live order is in neither the margin nor the warning about it.'
        );

        // And its money is named rather than quietly dropped.
        $this->assertSame(2, $summary['orders_uncosted']);
        $this->assertSame(275000.0, $summary['uncosted_revenue']);
    }

    /** A cancelled order is not a gap; it never counted in the first place. */
    public function test_cancelled_orders_are_in_neither_column(): void
    {
        $order = $this->buy($this->stocked('ryzen', 20000, null), 1);
        $order->forceFill(['status' => 'cancelled'])->save();

        $summary = SalesMargin::summary();

        $this->assertSame(0, $summary['orders_counted']);
        $this->assertSame(0, $summary['orders_uncosted']);
        $this->assertSame(0.0, $summary['uncosted_revenue']);
    }

    public function test_the_dashboard_shows_it(): void
    {
        $this->buy($this->stocked('ryzen', 20000, 14000), 1);

        $props = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/dashboard')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertSame(6000.0, $props['margin']['gross_profit']);
        $this->assertSame(1, $props['margin']['orders_counted']);
    }
}
