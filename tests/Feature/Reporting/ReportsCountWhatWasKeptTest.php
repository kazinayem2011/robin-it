<?php

namespace Tests\Feature\Reporting;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Services\StockService;
use App\Support\ProfitAndLoss;
use App\Support\Reports\ProductReport;
use App\Support\Reports\SalesReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports count what the customer kept, once it was delivered.
 *
 * A mouse returned and refunded showed a loss of ৳200 on a sale that made
 * ৳400: the refund came off, but the mouse still counted as sold at its price
 * and cost. A cancelled or fully returned order was left out and its refund
 * taken off as well, so the same money went twice. A pending order counted as
 * a sale the day it was placed, and lost stock never reached profit at all.
 *
 * Mice sell at ৳1,000 and cost ৳600.
 */
class ReportsCountWhatWasKeptTest extends TestCase
{
    use RefreshDatabase;

    private Product $mouse;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Mice', 'slug' => 'mice', 'is_active' => true]);
        $this->mouse = Product::create([
            'category_id' => $category->id, 'name' => 'Mouse', 'slug' => 'mouse',
            'price' => 1000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        app(StockService::class)->receive([], [['product_id' => $this->mouse->id, 'quantity' => 50, 'unit_cost' => 600]]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** Two mice, paid in full, taken as far as $to. */
    private function order(string $to = 'delivered'): Order
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $this->mouse->id, 'quantity' => 2])->assertOk();
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678', 'street_address' => 'House 1', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $order = Order::latest('id')->first();
        $order->payments()->create([
            'amount' => $order->total, 'method' => 'cash', 'received_on' => now()->toDateString(), 'received_by_name' => 'Till',
        ]);

        $orders = app(OrderService::class);

        if ($to === 'cancelled') {
            $orders->updateOrderStatus($order->fresh(), 'cancelled');
        } elseif ($to === 'delivered') {
            $orders->updateOrderStatus($order->fresh(), 'shipped');
            $orders->updateOrderStatus($order->fresh(), 'delivered');
        }

        return $order->fresh();
    }

    private function giveBack(Order $order, int $resellable, int $damaged = 0): void
    {
        app(OrderService::class)->returnOrder($order->fresh(), [
            ['order_item_id' => $order->items()->first()->id, 'resellable' => $resellable, 'damaged' => $damaged],
        ]);
    }

    private function refund(Order $order, float $amount): void
    {
        app(RefundService::class)->refund($order->fresh(), [
            'amount' => $amount, 'method' => 'bkash', 'reason' => 'returned', 'refunded_on' => now()->toDateString(),
        ], $this->admin->id);
    }

    private function statement(): array
    {
        return ProfitAndLoss::statement(now()->toDateString(), now()->toDateString());
    }

    public function test_a_returned_and_refunded_mouse_is_not_sold(): void
    {
        $order = $this->order();
        $this->giveBack($order, 1);
        $this->refund($order, 1000);

        $pl = $this->statement();
        $this->assertSame(1000.0, $pl['income']['goods'], 'one mouse kept');
        $this->assertSame(600.0, $pl['cost_of_goods'], 'the returned one is back on the shelf');
        $this->assertSame(0.0, $pl['income']['given_back'], 'the refund was for the mouse that came back');
        $this->assertSame(1000.0 + (float) $order->shipping_fee - 600.0, $pl['gross_profit']);

        $sales = SalesReport::for(now()->toDateString(), now()->toDateString())['totals'];
        $this->assertSame(1000.0, $sales['revenue']);
        $this->assertSame(1, $sales['units']);
        $this->assertSame(1000.0, $sales['net']);

        $products = ProductReport::for(now()->toDateString(), now()->toDateString())['totals'];
        $this->assertSame(1, $products['units']);
        $this->assertSame(400.0, (float) $products['profit']);
    }

    /* Left out, and so is the money that went back on it — once, not twice. */
    public function test_cancelled_and_fully_returned_orders_leave_no_trace(): void
    {
        $returned = $this->order();
        $this->giveBack($returned, 2);
        $this->refund($returned, (float) $returned->total);

        $cancelled = $this->order('cancelled');
        $this->refund($cancelled, (float) $cancelled->total);

        $pl = $this->statement();
        $this->assertSame(0.0, $pl['income']['total']);
        $this->assertSame(0.0, $pl['gross_profit']);
        $this->assertSame(0.0, SalesReport::for(now()->toDateString(), now()->toDateString())['totals']['net']);
    }

    public function test_an_order_counts_once_delivered_not_when_placed(): void
    {
        $this->order('pending');
        $this->assertSame(0.0, $this->statement()['income']['goods'], 'a pending order is not a sale yet');

        $order = $this->order();
        $this->assertNotNull($order->delivered_at, 'the day it was delivered is recorded');
        $this->assertSame(2000.0, $this->statement()['income']['goods']);
    }

    public function test_money_given_back_without_a_return_comes_off(): void
    {
        $order = $this->order();
        $this->refund($order, 300); // a scratch on the box, nothing returned

        $pl = $this->statement();
        $this->assertSame(2000.0, $pl['income']['goods']);
        $this->assertSame(300.0, $pl['income']['given_back']);
        $this->assertSame(2000.0 - 300.0 + (float) $order->shipping_fee, $pl['income']['total']);
    }

    public function test_a_damaged_return_is_stock_lost(): void
    {
        $order = $this->order();
        $this->giveBack($order, 0, 1);
        $this->refund($order, 1000);

        $pl = $this->statement();
        $this->assertSame(1000.0, $pl['income']['goods']);
        $this->assertSame(600.0, $pl['cost_of_goods']);
        $this->assertSame(600.0, $pl['stock_lost']['amount'], 'the broken mouse, at what it cost');
        $this->assertSame(1000.0 + (float) $order->shipping_fee - 600.0 - 600.0, $pl['gross_profit']);
    }

    public function test_a_stock_count_that_comes_up_short_is_stock_lost(): void
    {
        $stock = app(StockService::class);
        $stock->adjust($this->mouse, null, -2, 'stock_take', 'Two missing at the count');
        $stock->adjust($this->mouse, null, -1, 'supplier_return', 'Sent back for credit');

        $lost = $this->statement()['stock_lost'];
        $this->assertSame(1200.0, $lost['amount'], 'two mice at ৳600; the one sent back is not a loss');
        $this->assertSame(2, $lost['units']);
    }
}
