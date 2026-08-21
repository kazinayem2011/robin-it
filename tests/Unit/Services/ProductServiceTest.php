<?php

namespace Tests\Unit\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productService = app(ProductService::class);
    }

    public function test_get_filtered_products_returns_paginated_active_products(): void
    {
        $category = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'is_active' => true]);
        $brand = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);

        Product::create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'ASUS ROG Zephyrus G16',
            'slug' => 'asus-rog-zephyrus-g16',
            'price' => 220000,
            'discount_price' => 210000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Inactive Laptop',
            'slug' => 'inactive-laptop',
            'price' => 100000,
            'stock_quantity' => 0,
            'is_active' => false,
        ]);

        $paginator = $this->productService->getFilteredProducts(['category_slug' => 'laptops'], 10);

        $this->assertEquals(1, $paginator->total());
        $this->assertEquals('ASUS ROG Zephyrus G16', $paginator->items()[0]->name);
    }

    public function test_get_flash_sale_products_returns_discounted_items(): void
    {
        $category = Category::create(['name' => 'CPUs', 'slug' => 'cpu', 'is_active' => true]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Intel Core i9 14900K',
            'slug' => 'intel-core-i9-14900k',
            'price' => 75000,
            'discount_price' => 72000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $flashProducts = $this->productService->getFlashSaleProducts();

        $this->assertNotEmpty($flashProducts);
        $this->assertEquals('Intel Core i9 14900K', $flashProducts[0]['name']);
        $this->assertEquals('SAVE ৳3,000', $flashProducts[0]['save']);
    }

    public function test_format_product_card_data_calculates_savings_and_discount_percent(): void
    {
        $category = Category::create(['name' => 'Monitors', 'slug' => 'monitors', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Samsung Odyssey G9 OLED',
            'slug' => 'samsung-odyssey-g9-oled',
            'price' => 180000,
            'discount_price' => 153000,
            'stock_quantity' => 8,
            'is_active' => true,
        ]);

        $formatted = $this->productService->formatProductCardData($product);

        $this->assertEquals('৳153,000', $formatted['price']);
        $this->assertEquals('৳180,000', $formatted['oldPrice']);
        $this->assertEquals('SAVE ৳27,000', $formatted['save']);
        $this->assertEquals('-15%', $formatted['discount']);
    }
}
