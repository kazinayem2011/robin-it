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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What each step of an order's life does to the shelf.
 *
 * The rule being pinned: units leave once, at checkout, and come back exactly
 * once — never twice, and never for a status change that did not move any goods.
 */
class OrderStockLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 10): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ryzen 7 7800X3D',
            'slug' => 'ryzen-'.uniqid(),
            'price' => 45000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        if ($stock > 0) {
            app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => $stock]]);
        }

        return $product->fresh();
    }

    private function placeOrder(User $user, Product $product, int $qty = 3): Order
    {
        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id, 'quantity' => $qty,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45, Road 7', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::where('user_id', $user->id)->latest()->first();
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    public function test_checkout_takes_the_units_off_the_shelf_once(): void
    {
        $product = $this->product(10);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        $this->assertSame(7, $product->fresh()->stock_quantity);

        $sale = StockMovement::where('product_id', $product->id)
            ->where('type', StockMovement::SALE)->sole();

        $this->assertSame(-3, $sale->quantity);
        $this->assertSame(Order::class, $sale->reference_type);
        $this->assertSame($order->id, (int) $sale->reference_id);
    }

    /**
     * Approving an order moves paperwork, not goods. The units were already taken
     * at checkout, so none of these may take them a second time.
     */
    #[DataProvider('approvalStatusProvider')]
    public function test_approving_an_order_does_not_move_stock(string $status): void
    {
        $product = $this->product(10);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        $this->assertSame(7, $product->fresh()->stock_quantity);

        $this->orders()->updateOrderStatus($order->fresh(), $status);

        $this->assertSame(7, $product->fresh()->stock_quantity, "moving to {$status} changed stock");
        $this->assertSame(
            1,
            StockMovement::where('product_id', $product->id)->where('type', StockMovement::SALE)->count(),
            "moving to {$status} recorded a second sale"
        );
    }

    public static function approvalStatusProvider(): array
    {
        return [
            'processing' => ['processing'],
            'shipped' => ['shipped'],
            'delivered' => ['delivered'],
        ];
    }

    public function test_walking_the_whole_pipeline_takes_the_units_exactly_once(): void
    {
        $product = $this->product(10);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $this->orders()->updateOrderStatus($order->fresh(), $status);
        }

        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertFalse($this->stockService()->verify($product->fresh())['drifted']);
    }

    public function test_cancelling_returns_the_units(): void
    {
        $product = $this->product(10);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        $this->orders()->updateOrderStatus($order->fresh(), 'cancelled');

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertNotNull($order->fresh()->stock_released_at);
    }

    /**
     * The bug this replaced: cancelled -> pending -> cancelled restocked twice,
     * so a shelf of 10 became 13 and the shop sold units it did not own.
     */
    public function test_re_cancelling_an_order_cannot_restock_it_twice(): void
    {
        $product = $this->product(10);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        $this->orders()->updateOrderStatus($order->fresh(), 'cancelled');
        $this->assertSame(10, $product->fresh()->stock_quantity);

        // Reopening takes them back off the shelf...
        $this->orders()->updateOrderStatus($order->fresh(), 'pending');
        $this->assertSame(7, $product->fresh()->stock_quantity, 'reopening did not re-reserve');

        // ...so cancelling again returns them once, not a second time.
        $this->orders()->updateOrderStatus($order->fresh(), 'cancelled');
        $this->assertSame(10, $product->fresh()->stock_quantity, 'double restock');

        $this->assertFalse($this->stockService()->verify($product->fresh())['drifted']);
    }

    public function test_cancelling_twice_in_a_row_is_a_no_op(): void
    {
        $product = $this->product(10);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        $this->orders()->updateOrderStatus($order->fresh(), 'cancelled');
        $this->orders()->updateOrderStatus($order->fresh(), 'cancelled');

        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    /**
     * The units may have been sold to someone else while the order was cancelled.
     * Reopening must fail rather than promise stock the shop does not have.
     */
    public function test_reopening_fails_when_the_stock_is_gone(): void
    {
        $product = $this->product(3);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        $this->orders()->updateOrderStatus($order->fresh(), 'cancelled');
        $this->assertSame(3, $product->fresh()->stock_quantity);

        // Someone else buys the lot.
        $this->placeOrder(User::factory()->create(), $product, 3);
        $this->assertSame(0, $product->fresh()->stock_quantity);

        $this->expectExceptionMessage('Cannot reopen this order');
        $this->orders()->updateOrderStatus($order->fresh(), 'pending');
    }

    public function test_a_customer_cancellation_returns_the_units_once(): void
    {
        $user = User::factory()->create();
        $product = $this->product(10);
        $order = $this->placeOrder($user, $product, 4);

        $this->actingAs($user)->post("/account/orders/{$order->id}/cancel");
        $this->assertSame(10, $product->fresh()->stock_quantity);

        // A second attempt must not top the shelf up again.
        $this->actingAs($user)->post("/account/orders/{$order->id}/cancel");
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    private function stockService(): StockService
    {
        return app(StockService::class);
    }
}
