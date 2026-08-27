<?php

namespace Tests\Feature\Stock;

use App\Enums\ApiCode;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelling a delivered order put its units back on the shelf.
 *
 * At that point the goods are with the customer, so the shop was left holding
 * stock it does not have and would sell the same units a second time — the
 * exact failure the ledger exists to prevent.
 *
 * Goods that come back after delivery already have a correct path: a return,
 * which records how many came back and in what condition, so damaged units are
 * written off rather than resold.
 */
class CancelAfterDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ryzen 5',
            'slug' => 'ryzen-5',
            'price' => 1000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        app(StockService::class)->receive([], [[
            'product_id' => $this->product->id,
            'quantity' => 10,
        ]]);
    }

    private function placedOrder(int $qty = 2): Order
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id,
            'quantity' => $qty,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::latest('id')->first();
    }

    private function stock(): int
    {
        return (int) $this->product->fresh()->stock_quantity;
    }

    public function test_a_delivered_order_cannot_be_cancelled(): void
    {
        $orders = app(OrderService::class);
        $order = $this->placedOrder();

        $orders->updateOrderStatus($order, 'delivered');
        $this->assertSame(8, $this->stock());

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::VALIDATION_ERROR);

        $this->assertSame(8, $this->stock(), 'Stock was recreated for goods the customer has.');
        $this->assertSame('delivered', $order->fresh()->status);
    }

    /** The message has to point at the thing the admin should do instead. */
    public function test_the_refusal_names_the_return_flow(): void
    {
        $order = $this->placedOrder();
        app(OrderService::class)->updateOrderStatus($order, 'delivered');

        $admin = User::factory()->create(['role' => 'admin']);

        $message = $this->actingAs($admin)
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->json('message');

        $this->assertStringContainsString('return', strtolower($message));
    }

    /** And the path that is correct still works, with condition recorded. */
    public function test_goods_coming_back_after_delivery_go_through_a_return(): void
    {
        $orders = app(OrderService::class);
        $order = $this->placedOrder();
        $orders->updateOrderStatus($order, 'delivered');

        $orders->returnOrder($order->fresh(), [[
            'order_item_id' => $order->items->first()->id,
            'resellable' => 1,
            'damaged' => 1,
        ]]);

        // One back on the shelf; the damaged one is accounted for but not resold.
        $this->assertSame(9, $this->stock());
        $this->assertSame('returned', $order->fresh()->status);
    }

    /** Cancelling before dispatch is untouched — nothing has left the building. */
    public function test_cancelling_before_dispatch_still_returns_stock(): void
    {
        $orders = app(OrderService::class);

        foreach (['pending', 'processing'] as $from) {
            $this->product->fresh();
            $order = $this->placedOrder(1);

            if ($from !== 'pending') {
                $orders->updateOrderStatus($order, $from);
            }

            $before = $this->stock();
            $orders->updateOrderStatus($order->fresh(), 'cancelled');

            $this->assertSame($before + 1, $this->stock(), "Cancelling from {$from} should return the unit.");
        }
    }
}
