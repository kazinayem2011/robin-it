<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_add_item_to_cart_and_update_it(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Accessories', 'slug' => 'accessories', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Logitech G Pro X Superlight',
            'slug' => 'logitech-g-pro-x-superlight',
            'price' => 14500,
            'stock_quantity' => 12,
            'is_active' => true,
        ]);

        // Add to cart
        $response = $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('error', false);

        $itemId = $response->json('data.id');

        // Update item quantity
        $updateResponse = $this->actingAs($user)->patchJson('/api/'.str_replace('{itemId}', $itemId, ApiEndpoints::CART_ITEM), [
            'quantity' => 3,
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.quantity', 3);

        // Fetch cart
        $cartResponse = $this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART);
        $cartResponse->assertStatus(200)
            ->assertJsonPath('data.items.0.quantity', 3);

        // Delete item
        $deleteResponse = $this->actingAs($user)->deleteJson('/api/'.str_replace('{itemId}', $itemId, ApiEndpoints::CART_ITEM));
        $deleteResponse->assertStatus(200)
            ->assertJsonPath('error', false);
    }

    /**
     * Everything the cart page needs to stop at the right number.
     *
     * The "+" button used to be disabled only while a request was in flight, so
     * it would happily ask for a unit past the last one in stock and rely on
     * the server to refuse. These three are what let it stop instead: the stock
     * on the line, the product's minimum, and the per-item cap.
     */
    public function test_the_cart_payload_carries_the_quantity_bounds(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Storage', 'slug' => 'storage', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Samsung 990 Pro 2TB',
            'slug' => 'samsung-990-pro-2tb',
            'price' => 21000,
            'stock_quantity' => 3,
            'min_order_quantity' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $cart = $this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART);

        $cart->assertStatus(200)
            ->assertJsonPath('data.max_quantity_per_item', CartService::MAX_QUANTITY_PER_ITEM)
            ->assertJsonPath('data.items.0.product.stock_quantity', 3)
            ->assertJsonPath('data.items.0.product.min_order_quantity', 2);
    }

    /** The ceiling is real: the server refuses what the button now prevents. */
    public function test_the_server_refuses_more_than_is_in_stock(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'GPU', 'slug' => 'gpu', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'RTX 5090',
            'slug' => 'rtx-5090',
            'price' => 300000,
            'stock_quantity' => 2,
            'is_active' => true,
        ]);

        $added = $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $itemId = $added->json('data.id');

        $this->actingAs($user)
            ->patchJson('/api/'.str_replace('{itemId}', $itemId, ApiEndpoints::CART_ITEM), ['quantity' => 3])
            ->assertStatus(422);

        // And the cart still holds what it held.
        $this->actingAs($user)
            ->getJson('/api/'.ApiEndpoints::CART)
            ->assertJsonPath('data.items.0.quantity', 2);
    }
}
