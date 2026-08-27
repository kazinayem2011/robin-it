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

        $this->assertSame(40000.0, $summary['revenue']);
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

        $this->assertSame(18000.0, $summary['revenue']);   // 20000 less the 2000 code
        $this->assertSame(4000.0, $summary['gross_profit']);
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
