<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected OrderService $orderService;

    protected CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartService = app(CartService::class);
        $this->orderService = app(OrderService::class);
    }

    public function test_place_order_creates_order_and_items_and_decrements_stock(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'GPU', 'slug' => 'gpu', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'RTX 4070 Ti Super',
            'slug' => 'rtx-4070-ti-super',
            'price' => 110000,
            'discount_price' => 105000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $cart = $this->cartService->getOrCreateCart($user->id, null);
        $this->cartService->addItem($cart, $product->id, 2);

        $order = $this->orderService->placeOrder($cart, [
            'name' => 'Karim Ahmed',
            'phone' => '01711223344',
            'street_address' => 'House 12, Road 4, Banani',
            'city' => 'Dhaka',
            'payment_method' => 'COD',
        ], userId: $user->id);

        $this->assertNotNull($order);
        $this->assertStringStartsWith('ORD-', $order->order_number);
        $this->assertEquals(210000, $order->subtotal);
        $this->assertEquals(210060, $order->total);
        $this->assertEquals('pending', $order->status);
        $this->assertCount(1, $order->items);

        // Product stock should have decremented by 2 (10 - 2 = 8)
        $this->assertEquals(8, $product->fresh()->stock_quantity);
    }

    public function test_update_order_status(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mechanical Keyboard',
            'slug' => 'mechanical-keyboard',
            'price' => 5000,
            'stock_quantity' => 20,
            'is_active' => true,
        ]);

        $cart = $this->cartService->getOrCreateCart($user->id, null);
        $this->cartService->addItem($cart, $product->id, 1);

        $order = $this->orderService->placeOrder($cart, [
            'name' => 'Test User',
            'phone' => '01811223344',
            'street_address' => 'Dhanmondi 27',
            'city' => 'Dhaka',
        ], userId: $user->id);

        $updated = $this->orderService->updateOrderStatus($order, 'shipped');
        $this->assertEquals('shipped', $updated->status);
    }
}
