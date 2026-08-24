<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exact JSON keys the storefront reads.
 *
 * The option picker, the price and the stock badge are all driven by these; a
 * silent rename would leave the page rendering the parent product's numbers
 * while the shopper is buying an option.
 */
class VariantStorefrontContractTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'RAM', 'slug' => 'ram', 'is_active' => true]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kingston Fury Beast',
            'slug' => 'kingston-fury-beast',
            'price' => 4200,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        // Options first: a product holding stock can no longer be restructured.
        app(ProductVariantService::class)->convertToVariants($this->product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'price' => 4200, 'opening_stock' => 0],
            ['options' => ['Capacity' => '32GB'], 'price' => 8200, 'opening_stock' => 0],
        ]);

        $variants = $this->product->fresh('variants')->variants;

        app(StockService::class)->receive([], [
            ['product_id' => $this->product->id, 'product_variant_id' => $variants->firstWhere('name', '16GB')->id, 'quantity' => 6],
            ['product_id' => $this->product->id, 'product_variant_id' => $variants->firstWhere('name', '32GB')->id, 'quantity' => 4],
        ]);
    }

    public function test_the_detail_endpoint_exposes_the_options(): void
    {
        $response = $this->getJson('/api/products/kingston-fury-beast');
        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertTrue($data['has_variants']);
        $this->assertSame(['Capacity'], $data['variant_attributes']);

        // The picker iterates this key by name.
        $this->assertArrayHasKey('active_variants', $data, 'the option list key changed');
        $this->assertCount(2, $data['active_variants']);

        $first = $data['active_variants'][0];

        foreach (['id', 'name', 'stock_quantity', 'effective_price', 'in_stock'] as $key) {
            $this->assertArrayHasKey($key, $first, "option is missing {$key}");
        }

        $this->assertSame('16GB', $first['name']);
        $this->assertSame(6, $first['stock_quantity']);
        $this->assertEqualsWithDelta(4200.0, $first['effective_price'], 0.01);
    }

    public function test_a_sold_out_option_is_still_listed_so_it_can_be_shown_struck_through(): void
    {
        $variant = $this->product->fresh('variants')->variants->firstWhere('name', '32GB');
        app(StockService::class)->adjust($this->product->fresh(), $variant, -4, 'lost');

        $data = $this->getJson('/api/products/kingston-fury-beast')->json('data');

        $soldOut = collect($data['active_variants'])->firstWhere('name', '32GB');

        $this->assertNotNull($soldOut, 'a sold-out option disappeared from the page');
        $this->assertSame(0, $soldOut['stock_quantity']);
        $this->assertFalse($soldOut['in_stock']);
    }

    public function test_the_cart_response_names_the_chosen_option(): void
    {
        $user = User::factory()->create();
        $variant = $this->product->fresh('variants')->variants->firstWhere('name', '32GB');

        $response = $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $this->product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);
        $response->assertStatus(200);

        // Cart.jsx reads item.variant.name and item.variant.effective_price.
        $this->assertSame('32GB', $response->json('data.variant.name'));
        $this->assertEqualsWithDelta(8200.0, $response->json('data.variant.effective_price'), 0.01);

        $cart = $this->actingAs($user)->getJson('/cart-api')->json('data');
        $line = $cart['items'][0];

        $this->assertSame('32GB', $line['variant']['name']);
    }

    public function test_the_single_product_path_is_unchanged(): void
    {
        $single = Product::create([
            'category_id' => $this->product->category_id,
            'name' => 'Plain Product', 'slug' => 'plain-product',
            'price' => 999, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        app(StockService::class)->receive([], [['product_id' => $single->id, 'quantity' => 3]]);

        $data = $this->getJson('/api/products/plain-product')->json('data');

        $this->assertFalse($data['has_variants']);
        $this->assertSame(3, $data['stock_quantity']);
        $this->assertTrue($data['in_stock']);
    }
}
