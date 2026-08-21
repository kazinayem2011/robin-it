<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_fetch_products_list_via_api(): void
    {
        $category = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'is_active' => true]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'MacBook Pro M3 Max',
            'slug' => 'macbook-pro-m3-max',
            'price' => 385000,
            'stock_quantity' => 4,
            'is_active' => true,
        ]);

        $response = $this->getJson('/'.ApiEndpoints::API_PREFIX.'/'.ApiEndpoints::PRODUCTS_INDEX);

        // Rows sit directly in `data`; paging lives in `meta`.
        $response->assertStatus(200)
            ->assertJsonPath('error', false)
            ->assertJsonStructure([
                'error',
                'message',
                'data' => [
                    '*' => ['id', 'name', 'slug', 'price', 'inStock', 'stockQuantity'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('data.0.name', 'MacBook Pro M3 Max')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_can_fetch_flash_sale_products_via_api(): void
    {
        $category = Category::create(['name' => 'Monitors', 'slug' => 'monitors', 'is_active' => true]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'ASUS ROG Swift OLED 240Hz',
            'slug' => 'asus-rog-swift-oled-240hz',
            'price' => 140000,
            'discount_price' => 125000,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);

        $response = $this->getJson('/'.ApiEndpoints::API_PREFIX.'/'.ApiEndpoints::PRODUCTS_FLASH_SALE);

        $response->assertStatus(200)
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.0.name', 'ASUS ROG Swift OLED 240Hz');
    }

    public function test_can_fetch_single_product_details_via_api(): void
    {
        $category = Category::create(['name' => 'Processors', 'slug' => 'cpu', 'is_active' => true]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'AMD Ryzen 9 7950X3D',
            'slug' => 'amd-ryzen-9-7950x3d',
            'price' => 78000,
            'stock_quantity' => 7,
            'is_active' => true,
        ]);

        $response = $this->getJson('/'.ApiEndpoints::API_PREFIX.'/products/amd-ryzen-9-7950x3d');

        $response->assertStatus(200)
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.name', 'AMD Ryzen 9 7950X3D');
    }

    public function test_can_fetch_pc_builder_blueprint_categories(): void
    {
        $response = $this->getJson('/'.ApiEndpoints::API_PREFIX.'/'.ApiEndpoints::PC_BUILDER_CATEGORIES);

        $response->assertStatus(200)
            ->assertJsonPath('error', false)
            ->assertJsonStructure([
                'error',
                'message',
                'data' => [
                    '*' => ['id', 'name', 'category_slug', 'required', 'icon'],
                ],
            ]);
    }
}
