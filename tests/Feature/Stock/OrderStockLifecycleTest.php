<?php

namespace Tests\Feature\Stock;

use App\Exceptions\StorefrontException;
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
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $product->id, 'quantity' => $qty,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
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
     * Cancelled is an end state.
     *
     * It used to be reopenable, and the stock side of that was careful — units
     * came back off the shelf, and the move failed if they had been sold. What
     * it could not undo is everything outside this table: the customer has
     * been told the order was cancelled, and any refund raised against it
     * still stands. So the shelf keeps the units and a change of mind is a new
     * order, which those units can cover straight away.
     *
     * The bug that made reopening dangerous in the first place:
     * cancelled -> pending -> cancelled restocked twice, so a shelf of 10
     * became 13 and the shop sold units it did not own.
     */
    public function test_a_cancelled_order_cannot_be_reopened(): void
    {
        $product = $this->product(10);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        $this->orders()->updateOrderStatus($order->fresh(), 'cancelled');
        $this->assertSame(10, $product->fresh()->stock_quantity);

        foreach (['pending', 'processing', 'shipped', 'delivered'] as $status) {
            try {
                $this->orders()->updateOrderStatus($order->fresh(), $status);
                $this->fail("A cancelled order was moved to '{$status}'.");
            } catch (StorefrontException $e) {
                $this->assertStringContainsString('cannot be reopened', $e->getMessage());
            }
        }

        $this->assertSame('cancelled', $order->fresh()->status);
        // And the shelf is untouched by the attempts — no unit moved either way.
        $this->assertSame(10, $product->fresh()->stock_quantity);
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
     * The units released by a cancellation are genuinely free.
     *
     * This used to be the awkward case — reopening had to check whether the
     * stock was still there and refuse if someone else had bought it. With
     * cancelled as an end state the question does not arise: the units went
     * back on the shelf and the next customer may have them.
     */
    public function test_units_freed_by_a_cancellation_can_be_sold_to_someone_else(): void
    {
        $product = $this->product(3);
        $order = $this->placeOrder(User::factory()->create(), $product, 3);

        $this->orders()->updateOrderStatus($order->fresh(), 'cancelled');
        $this->assertSame(3, $product->fresh()->stock_quantity);

        // Someone else buys the lot, which is the point of releasing them.
        $this->placeOrder(User::factory()->create(), $product, 3);
        $this->assertSame(0, $product->fresh()->stock_quantity);

        $this->assertFalse($this->stockService()->verify($product->fresh())['drifted']);
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
