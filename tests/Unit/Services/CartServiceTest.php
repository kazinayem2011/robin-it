<?php

namespace Tests\Unit\Services;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartService = app(CartService::class);
    }

    public function test_get_or_create_cart_for_user_and_guest(): void
    {
        $user = User::factory()->create();
        $userCart = $this->cartService->getOrCreateCart($user->id, null);

        $this->assertInstanceOf(Cart::class, $userCart);
        $this->assertEquals($user->id, $userCart->user_id);

        $guestCart = $this->cartService->getOrCreateCart(null, 'test-session-123');
        $this->assertInstanceOf(Cart::class, $guestCart);
        $this->assertEquals('test-session-123', $guestCart->session_id);
    }

    public function test_add_item_and_update_quantity(): void
    {
        $category = Category::create(['name' => 'RAM', 'slug' => 'ram', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Corsair Vengeance 32GB',
            'slug' => 'corsair-vengeance-32gb',
            'price' => 15000,
            'discount_price' => 14000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $cart = $this->cartService->getOrCreateCart(null, 'session-abc');
        $item = $this->cartService->addItem($cart, $product->id, 2);

        $this->assertEquals(2, $item->quantity);
        $this->assertEquals($product->id, $item->product_id);

        $updatedItem = $this->cartService->updateItemQuantity($cart, $item->id, 4);
        $this->assertEquals(4, $updatedItem->quantity);
    }

    public function test_calculate_totals(): void
    {
        $category = Category::create(['name' => 'Storage', 'slug' => 'storage', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Samsung 990 Pro 2TB',
            'slug' => 'samsung-990-pro-2tb',
            'price' => 22000,
            'discount_price' => 20000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $cart = $this->cartService->getOrCreateCart(null, 'session-xyz');
        $this->cartService->addItem($cart, $product->id, 2);

        $totals = $this->cartService->calculateTotals($cart, shippingFee: 60);

        // 2 * 20000 = 40000 subtotal + 60 shipping = 40060
        $this->assertEquals(40000, $totals['subtotal']);
        $this->assertEquals(60, $totals['shipping_fee']);
        $this->assertEquals(40060, $totals['total']);
        $this->assertEquals(2, $totals['total_items']);
    }
}
