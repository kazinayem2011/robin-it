<?php

namespace Tests\Feature\Customer;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Customers had no way to call off an order — the backend could already restock
 * on cancellation, but only an admin could trigger it.
 */
class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 10): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test CPU',
            'slug' => 'test-cpu-'.uniqid(),
            'price' => 10000,
            'stock_quantity' => $stock,
            'is_active' => true,
        ]);
    }

    private function placeOrder(User $user, Product $product, int $qty = 2): Order
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

    private function cancel(User $user, Order $order)
    {
        return $this->actingAs($user)->post("/account/orders/{$order->id}/cancel");
    }

    public function test_a_customer_can_cancel_a_pending_order(): void
    {
        $user = User::factory()->create();
        $product = $this->product(10);
        $order = $this->placeOrder($user, $product, 2);

        $this->assertSame(8, $product->fresh()->stock_quantity);

        $this->cancel($user, $order)->assertRedirect()->assertSessionHas('success');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_cancelling_returns_the_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->product(10);
        $order = $this->placeOrder($user, $product, 3);

        $this->assertSame(7, $product->fresh()->stock_quantity);

        $this->cancel($user, $order);

        $this->assertSame(10, $product->fresh()->stock_quantity, 'Cancelled units must become sellable again.');
    }

    public static function cancellableStatusProvider(): array
    {
        return [
            'pending' => ['pending', true],
            'processing' => ['processing', true],
            'shipped' => ['shipped', false],
            'delivered' => ['delivered', false],
        ];
    }

    #[DataProvider('cancellableStatusProvider')]
    public function test_only_orders_that_have_not_shipped_can_be_cancelled(string $status, bool $allowed): void
    {
        $user = User::factory()->create();
        $order = $this->placeOrder($user, $this->product(10), 1);
        $order->update(['status' => $status]);

        $this->cancel($user, $order);

        $this->assertSame(
            $allowed ? 'cancelled' : $status,
            $order->fresh()->status,
            "An order in '{$status}' should ".($allowed ? '' : 'not ').'be cancellable by the customer.'
        );
    }

    public function test_a_dispatched_order_explains_what_to_do_instead(): void
    {
        $user = User::factory()->create();
        $order = $this->placeOrder($user, $this->product(10), 1);
        $order->update(['status' => 'shipped']);

        $this->cancel($user, $order)->assertSessionHas('error');

        $this->assertStringContainsString(
            'contact support',
            session('error'),
            'The customer should be told how to proceed, not just refused.'
        );
    }

    public function test_a_customer_cannot_cancel_someone_elses_order(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $product = $this->product(10);
        $order = $this->placeOrder($owner, $product, 2);

        $this->cancel($attacker, $order)->assertSessionHas('error');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock_quantity, 'Stock must not move.');
    }

    public function test_cancelling_twice_does_not_restock_twice(): void
    {
        $user = User::factory()->create();
        $product = $this->product(10);
        $order = $this->placeOrder($user, $product, 3);

        $this->cancel($user, $order);
        $this->assertSame(10, $product->fresh()->stock_quantity);

        $this->cancel($user, $order)->assertSessionHas('error');

        $this->assertSame(10, $product->fresh()->stock_quantity, 'A second cancel must not inflate stock.');
    }

    public function test_a_guest_cannot_cancel_orders(): void
    {
        // Built directly rather than through the checkout flow: actingAs() persists
        // for the rest of the test, so a "guest" request after one would still be
        // authenticated and would cancel successfully.
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-GUESTTEST1',
            'subtotal' => 100, 'shipping_fee' => 60, 'discount' => 0, 'total' => 160,
            'status' => 'pending', 'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'A', 'phone' => '01712345678', 'street_address' => 'x', 'city' => 'Dhaka'],
        ]);

        $this->post("/account/orders/{$order->id}/cancel")->assertRedirect('/login');

        $this->assertSame('pending', $order->fresh()->status);
    }
}
