<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
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
}
