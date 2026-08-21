<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_complete_checkout_and_place_order(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Monitors', 'slug' => 'monitors', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Dell UltraSharp 32 4K',
            'slug' => 'dell-ultrasharp-32-4k',
            'price' => 85000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        // Add to cart first as user
        $this->actingAs($user)->postJson('/'.ApiEndpoints::CART, [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(200);

        // Submit checkout as user
        $checkoutResponse = $this->actingAs($user)->postJson('/'.ApiEndpoints::CHECKOUT, [
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'street_address' => 'House 45, Road 7, Gulshan 2',
            'city' => 'Dhaka',
            'zone' => 'Gulshan',
            'payment_method' => 'COD',
        ]);

        $checkoutResponse->assertStatus(201)
            ->assertJsonPath('error', false)
            ->assertJsonStructure([
                'error',
                'data' => ['order_id', 'order_number', 'subtotal', 'shipping_fee', 'discount', 'total'],
            ]);

        $this->assertDatabaseHas('orders', [
            'total' => 85060,
            'payment_method' => 'COD',
            'status' => 'pending',
        ]);

        // Cart should now be empty
        $cartResponse = $this->actingAs($user)->getJson('/'.ApiEndpoints::CART);
        $this->assertEmpty($cartResponse->json('data.items'));
    }

    public function test_checkout_fails_if_cart_is_empty(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/'.ApiEndpoints::CHECKOUT, [
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'street_address' => 'House 45, Road 7, Gulshan 2',
            'city' => 'Dhaka',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', true)
            ->assertJsonPath('code', 'CART_EMPTY');
    }
}
