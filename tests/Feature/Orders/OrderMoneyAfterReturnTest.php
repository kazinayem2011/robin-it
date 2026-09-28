<?php

namespace Tests\Feature\Orders;

use App\Exceptions\StorefrontException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an order is worth, and owes, after goods come back.
 *
 * A mouse returned and refunded still read "৳1,500 owed", because a return
 * did not reduce the order's value. A partial return closed the whole order,
 * so the rest could never come back later. A shipped order could be moved
 * back to Pending, and a returned one tracked as "Order Placed".
 */
class OrderMoneyAfterReturnTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Mice', 'slug' => 'mice', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id, 'name' => 'Mouse', 'slug' => 'mouse',
            'price' => 1000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        app(StockService::class)->receive([], [['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 600]]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** Two mice, inside Dhaka, paid in full and delivered. */
    private function deliveredOrder(bool $paid = true): Order
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $this->product->id, 'quantity' => 2])->assertOk();
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678', 'street_address' => 'House 1', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $order = Order::latest('id')->first();

        if ($paid) {
            $order->payments()->create([
                'amount' => $order->total, 'method' => 'cash', 'received_on' => now()->toDateString(), 'received_by_name' => 'Till',
            ]);
        }

        $orders = app(OrderService::class);
        $orders->updateOrderStatus($order->fresh(), 'shipped');
        $orders->updateOrderStatus($order->fresh(), 'delivered');

        return $order->fresh();
    }

    private function giveBack(Order $order, int $resellable): Order
    {
        $item = $order->items()->first();

        return app(OrderService::class)->returnOrder($order, [
            ['order_item_id' => $item->id, 'resellable' => $resellable, 'damaged' => 0],
        ]);
    }

    public function test_a_returned_and_refunded_item_leaves_nothing_owed(): void
    {
        $order = $this->deliveredOrder();
        $this->assertSame(2000.0 + (float) $order->shipping_fee, (float) $order->total); // 2 × 1000 + delivery

        $this->giveBack($order, 1);
        $order = $order->fresh();
        $this->assertSame(1000.0, $order->returned_value);

        app(RefundService::class)->refund($order, [
            'amount' => 1000, 'method' => 'bkash', 'reason' => 'returned', 'refunded_on' => now()->toDateString(),
        ], $this->admin->id);

        $this->assertSame(0.0, $order->fresh()->amount_due, 'nothing owed after the refund');
    }

    /* Before the refund the shop owes the customer, not the other way round. */
    public function test_a_return_on_a_paid_order_does_not_make_the_customer_owe(): void
    {
        $order = $this->deliveredOrder();
        $this->giveBack($order, 1);

        $this->assertSame(0.0, $order->fresh()->amount_due);
        $this->assertSame((float) $order->total, $order->fresh()->refundable_amount, 'the money can be given back');
    }

    public function test_a_return_on_an_unpaid_order_lowers_what_is_owed(): void
    {
        $order = $this->deliveredOrder(paid: false);
        $this->giveBack($order, 1);

        $this->assertSame(1000.0 + (float) $order->shipping_fee, $order->fresh()->amount_due, 'one mouse and the delivery');
    }

    public function test_a_partial_return_leaves_the_order_open_for_more(): void
    {
        $order = $this->deliveredOrder();

        $this->giveBack($order, 1);
        $this->assertSame('delivered', $order->fresh()->status, 'still delivered, one mouse kept');

        $this->giveBack($order->fresh(), 1);
        $this->assertSame('returned', $order->fresh()->status, 'returned once everything is back');
        $this->assertSame(20, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_more_cannot_come_back_than_was_bought(): void
    {
        $order = $this->deliveredOrder();
        $this->giveBack($order, 1);

        $this->expectException(StorefrontException::class);
        $this->giveBack($order->fresh(), 2);
    }

    public function test_an_order_cannot_go_backwards(): void
    {
        $order = $this->deliveredOrder();

        try {
            app(OrderService::class)->updateOrderStatus($order, 'pending');
            $this->fail('A delivered order went back to Pending.');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('cannot go back from Delivered to Pending', $e->getMessage());
        }

        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_a_returned_order_tracks_as_returned(): void
    {
        $order = $this->deliveredOrder();
        $this->giveBack($order, 2);

        $track = app(OrderService::class)->trackOrder($order->order_number, '01712345678');

        $this->assertSame('Returned', $track['status_label']);
    }

    /* The invoice showed only the total, and asked for all of it at the door. */
    public function test_the_invoice_shows_what_was_paid_and_what_is_due(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $this->product->id, 'quantity' => 2])->assertOk();
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678', 'street_address' => 'House 1', 'city' => 'Dhaka',
        ])->assertStatus(201);
        $order = Order::latest('id')->first();
        $order->payments()->create([
            'amount' => 500, 'method' => 'cash', 'received_on' => now()->toDateString(), 'received_by_name' => 'Till',
        ]);
        app(OrderService::class)->updateOrderStatus($order->fresh(), 'shipped');

        $html = $this->actingAs($this->admin)->get("/orders/{$order->id}/invoice")->assertOk()->getContent();

        $this->assertStringContainsString('Part paid', $html);
        $this->assertStringContainsString('Balance due', $html);
        $due = number_format((float) $order->total - 500, 2);
        $this->assertStringContainsString("Please have ৳{$due} ready", $html, 'the amount still due, not the total');
    }
}
