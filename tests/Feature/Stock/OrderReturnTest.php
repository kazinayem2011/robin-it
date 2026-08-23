<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Returns on a delivered order.
 *
 * Resellable units go back to the shelf; damaged ones are recorded and written
 * off, so a broken part can never be sold on to the next customer.
 */
class OrderReturnTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 10): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'GPU', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'RTX 4070 Super',
            'slug' => 'rtx-'.uniqid(),
            'price' => 82000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => $stock]]);

        return $product->fresh();
    }

    private function deliveredOrder(User $user, Product $product, int $qty = 3): Order
    {
        $this->actingAs($user)->postJson('/cart-api', ['product_id' => $product->id, 'quantity' => $qty]);
        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ]);

        $order = Order::where('user_id', $user->id)->latest()->first();
        app(OrderService::class)->updateOrderStatus($order, 'delivered');

        return $order->fresh('items');
    }

    public function test_a_resellable_return_goes_back_on_the_shelf(): void
    {
        $product = $this->product(10);
        $order = $this->deliveredOrder(User::factory()->create(), $product, 3);
        $item = $order->items->first();

        $this->assertSame(7, $product->fresh()->stock_quantity);

        app(OrderService::class)->returnOrder($order, [
            ['order_item_id' => $item->id, 'resellable' => 3, 'damaged' => 0],
        ]);

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame(3, $item->fresh()->returned_quantity);
    }

    /** A damaged return is accounted for, but must never become sellable again. */
    public function test_a_damaged_return_is_written_off_not_resold(): void
    {
        $product = $this->product(10);
        $order = $this->deliveredOrder(User::factory()->create(), $product, 3);
        $item = $order->items->first();

        app(OrderService::class)->returnOrder($order, [
            ['order_item_id' => $item->id, 'resellable' => 1, 'damaged' => 2],
        ]);

        // Only the resellable unit is back; the two damaged ones are not.
        $this->assertSame(8, $product->fresh()->stock_quantity);

        $writeOff = StockMovement::where('product_id', $product->id)
            ->where('type', StockMovement::WRITE_OFF)->sole();

        $this->assertSame(-2, $writeOff->quantity);
        $this->assertSame('damaged', $writeOff->reason);

        // The loss is visible in the ledger rather than silently absorbed.
        $this->assertFalse(app(StockService::class)->verify($product->fresh())['drifted']);
    }

    public function test_you_cannot_return_more_than_was_bought(): void
    {
        $product = $this->product(10);
        $order = $this->deliveredOrder(User::factory()->create(), $product, 2);
        $item = $order->items->first();

        $this->expectExceptionMessage('only 2 of that line are still outstanding');

        app(OrderService::class)->returnOrder($order, [
            ['order_item_id' => $item->id, 'resellable' => 5],
        ]);
    }

    public function test_only_a_delivered_order_can_be_returned(): void
    {
        $product = $this->product(10);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/cart-api', ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ]);
        $order = Order::where('user_id', $user->id)->latest()->first()->load('items');

        $this->expectExceptionMessage('Only a delivered order can be returned');
        app(OrderService::class)->returnOrder($order, [
            ['order_item_id' => $order->items->first()->id, 'resellable' => 1],
        ]);
    }

    public function test_an_order_cannot_be_returned_twice(): void
    {
        $product = $this->product(10);
        $order = $this->deliveredOrder(User::factory()->create(), $product, 3);
        $item = $order->items->first();

        app(OrderService::class)->returnOrder($order, [
            ['order_item_id' => $item->id, 'resellable' => 3],
        ]);
        $this->assertSame(10, $product->fresh()->stock_quantity);

        $this->expectExceptionMessage('already been returned');
        app(OrderService::class)->returnOrder($order->fresh(), [
            ['order_item_id' => $item->id, 'resellable' => 3],
        ]);
    }

    public function test_a_returned_order_cannot_change_status_again(): void
    {
        $product = $this->product(10);
        $order = $this->deliveredOrder(User::factory()->create(), $product, 2);

        app(OrderService::class)->returnOrder($order, [
            ['order_item_id' => $order->items->first()->id, 'resellable' => 2],
        ]);

        $this->expectExceptionMessage('can no longer change status');
        app(OrderService::class)->updateOrderStatus($order->fresh(), 'delivered');
    }

    /** A return needs each item's condition, so it cannot be a bare status change. */
    public function test_returned_is_not_reachable_as_a_plain_status_update(): void
    {
        $product = $this->product(10);
        $order = $this->deliveredOrder(User::factory()->create(), $product, 2);

        $this->expectExceptionMessage('Process this as a return');
        app(OrderService::class)->updateOrderStatus($order->fresh(), 'returned');
    }

    public function test_an_admin_can_process_a_return_over_http(): void
    {
        $product = $this->product(10);
        $order = $this->deliveredOrder(User::factory()->create(), $product, 3);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/return", [
            'lines' => [[
                'order_item_id' => $order->items->first()->id,
                'resellable' => 2,
                'damaged' => 1,
            ]],
            'note' => 'Customer reported coil whine on one card',
        ])->assertStatus(200);

        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertSame('returned', $order->fresh()->status);
    }

    public function test_a_customer_cannot_process_a_return(): void
    {
        $product = $this->product(10);
        $order = $this->deliveredOrder(User::factory()->create(), $product, 3);

        $response = $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson("/api/admin/orders/{$order->id}/return", [
                'lines' => [['order_item_id' => $order->items->first()->id, 'resellable' => 3]],
            ]);

        $this->assertContains($response->status(), [302, 403]);
        $this->assertSame(7, $product->fresh()->stock_quantity);
    }
}
