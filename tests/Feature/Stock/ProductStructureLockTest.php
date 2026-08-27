<?php

namespace Tests\Feature\Stock;

use App\Exceptions\StorefrontException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product's structure is fixed once it has history.
 *
 * Switching between a single stock pool and per-option stock means moving
 * units between shelves that past records already point at. It stays available
 * while nothing has happened yet, so a mis-clicked toggle can be corrected, and
 * closes the moment there is anything to corrupt.
 */
class ProductStructureLockTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id, 'name' => 'Memory Kit',
            'slug' => 'memory-kit-'.uniqid(), 'price' => 4200,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    private function variants(): ProductVariantService
    {
        return app(ProductVariantService::class);
    }

    /** A product created moments ago has nothing to lose. */
    public function test_a_product_with_no_history_can_still_be_structured(): void
    {
        $product = $this->product();

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 0],
        ]);

        $this->assertTrue($product->fresh()->has_variants);
    }

    public function test_a_product_that_has_taken_stock_cannot_be_restructured(): void
    {
        $product = $this->product();
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 5]]);

        $this->expectExceptionMessage('stock has been recorded against it');

        $this->variants()->convertToVariants($product->fresh(), ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 5],
        ]);
    }

    public function test_a_variant_product_with_stock_cannot_be_collapsed(): void
    {
        $product = $this->product();
        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 0],
        ]);

        app(StockService::class)->receive([], [[
            'product_id' => $product->id,
            'product_variant_id' => $product->fresh('variants')->variants->first()->id,
            'quantity' => 4,
        ]]);

        $this->expectExceptionMessage('stock has been recorded against it');
        $this->variants()->convertToSingle($product->fresh());
    }

    /** Even with the stock cleared, an order still points at the old shape. */
    public function test_a_product_that_has_been_sold_cannot_be_restructured(): void
    {
        $product = $this->product();
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 3]]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->assertSame(1, Order::count());

        try {
            $this->variants()->convertToVariants($product->fresh(), ['Capacity'], [
                ['options' => ['Capacity' => '16GB'], 'opening_stock' => 2],
            ]);
            $this->fail('a sold product was restructured');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('past orders', $e->getMessage());
        }
    }

    public function test_the_refusal_says_what_to_do_instead(): void
    {
        $product = $this->product();
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 2]]);

        try {
            $this->variants()->convertToVariants($product->fresh(), ['Capacity'], [
                ['options' => ['Capacity' => '16GB'], 'opening_stock' => 2],
            ]);
            $this->fail('expected a refusal');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('Create a new product', $e->getMessage());
        }
    }

    public function test_the_admin_form_is_told_the_structure_is_fixed(): void
    {
        $product = $this->product();
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 2]]);

        $props = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/products')
            ->viewData('page')['props'];

        $row = collect($props['products']['data'])->firstWhere('id', $product->id);

        $this->assertTrue($row['structure_locked'], 'the toggle would be offered and then refused');
    }

    public function test_a_fresh_product_is_not_reported_as_locked(): void
    {
        $product = $this->product();

        $props = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/products')
            ->viewData('page')['props'];

        $row = collect($props['products']['data'])->firstWhere('id', $product->id);

        $this->assertFalse($row['structure_locked']);
    }
}
