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
 * Knowing what to buy, and what the shelf is worth.
 *
 * "Low stock" used to be a single hardcoded 10 for the whole catalogue, which
 * is wrong in both directions: ten cables is nearly out, ten flagship graphics
 * cards is months of inventory.
 */
class ReorderAndValuationTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, int $stock, ?int $reorderLevel = null, ?float $unitCost = null): Product
    {
        $category = Category::firstOrCreate(['slug' => 'parts'], ['name' => 'Parts', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => 1000,
            'stock_quantity' => 0,
            'reorder_level' => $reorderLevel,
            'is_active' => true,
        ]);

        if ($stock > 0) {
            app(StockService::class)->receive([], [[
                'product_id' => $product->id,
                'quantity' => $stock,
                'unit_cost' => $unitCost,
            ]]);
        }

        return $product->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function summary(): array
    {
        $response = $this->actingAs($this->admin())->get('/admin/stock');
        $response->assertStatus(200);

        return $response->viewData('page')['props']['summary'];
    }

    public function test_a_product_uses_its_own_reorder_level_not_a_global_one(): void
    {
        // 40 cables with a level of 100 is low; 3 flagship cards with a level
        // of 2 is not. A single global threshold gets both wrong.
        $cables = $this->product('HDMI Cable', 40, reorderLevel: 100);
        $flagship = $this->product('RTX 4090', 3, reorderLevel: 2);

        $this->assertTrue($cables->fresh()->needsReorder());
        $this->assertFalse($flagship->fresh()->needsReorder());
    }

    public function test_a_product_without_its_own_level_falls_back_to_the_default(): void
    {
        config(['inventory.default_reorder_level' => 10]);

        $low = $this->product('Thermal Paste', 8);
        $fine = $this->product('Case Fan', 25);

        $this->assertSame(10, $low->fresh()->reorderLevel());
        $this->assertTrue($low->fresh()->needsReorder());
        $this->assertFalse($fine->fresh()->needsReorder());
    }

    public function test_the_scope_judges_each_row_by_its_own_level(): void
    {
        config(['inventory.default_reorder_level' => 10]);

        $this->product('Needs restocking', 5, reorderLevel: 20);
        $this->product('Plenty', 500, reorderLevel: 20);
        $this->product('Default and low', 3);
        $this->product('Default and fine', 80);

        $names = Product::needingReorder()->pluck('name')->all();

        sort($names);
        $this->assertSame(['Default and low', 'Needs restocking'], $names);
    }

    public function test_an_inactive_product_is_never_flagged_for_reordering(): void
    {
        $product = $this->product('Discontinued', 0, reorderLevel: 10);
        $product->update(['is_active' => false]);

        $this->assertFalse($product->fresh()->needsReorder());
        $this->assertSame(0, Product::needingReorder()->count());
    }

    public function test_an_option_falls_back_to_its_parent_then_the_default(): void
    {
        config(['inventory.default_reorder_level' => 10]);

        // No stock yet: a product holding stock can no longer be restructured.
        $product = $this->product('Memory Kit', 0, reorderLevel: 30);

        app(ProductVariantService::class)->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'reorder_level' => 4, 'opening_stock' => 0],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 0],
        ]);

        $variants = $product->fresh('variants')->variants;
        app(StockService::class)->receive([], [
            ['product_id' => $product->id, 'product_variant_id' => $variants->firstWhere('name', '16GB')->id, 'quantity' => 12],
            ['product_id' => $product->id, 'product_variant_id' => $variants->firstWhere('name', '32GB')->id, 'quantity' => 8],
        ]);

        $product = $product->fresh('variants');
        $own = $product->variants->firstWhere('name', '16GB');
        $inherits = $product->variants->firstWhere('name', '32GB');

        $this->assertSame(4, $own->reorderLevel(), 'the option ignored its own level');
        $this->assertFalse($own->needsReorder());

        $this->assertSame(30, $inherits->reorderLevel(), 'the option did not inherit from the product');
        $this->assertTrue($inherits->needsReorder());
    }

    /** Valuation is what the stock cost, not what it will sell for. */
    public function test_the_valuation_uses_purchase_cost_not_retail_price(): void
    {
        $this->product('Costed Part', 10, unitCost: 250.0);

        $summary = $this->summary();

        // 10 x 250 cost, despite a 1000 retail price.
        $this->assertEqualsWithDelta(2500.0, $summary['valuation'], 0.01);
        $this->assertSame(10, $summary['units']);
        $this->assertSame(0, $summary['uncosted_units']);
    }

    public function test_the_latest_purchase_price_wins(): void
    {
        $product = $this->product('Repriced Part', 10, unitCost: 100.0);

        // A later delivery at a higher price.
        app(StockService::class)->receive([], [[
            'product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 300.0,
        ]]);

        $this->assertEqualsWithDelta(6000.0, $this->summary()['valuation'], 0.01);
    }

    /**
     * Stock that never came through a costed delivery has no price to value it
     * at. Guessing would make the total quietly wrong, so it is reported
     * separately instead.
     */
    public function test_units_with_no_recorded_cost_are_reported_not_guessed(): void
    {
        $this->product('Costed', 4, unitCost: 500.0);
        $this->product('Uncosted', 6);

        $summary = $this->summary();

        $this->assertEqualsWithDelta(2000.0, $summary['valuation'], 0.01);
        $this->assertSame(6, $summary['uncosted_units']);
        $this->assertSame(10, $summary['units']);
    }

    public function test_the_reorder_filter_narrows_the_list(): void
    {
        config(['inventory.default_reorder_level' => 10]);
        $this->product('Running out', 2);
        $this->product('Well stocked', 90);

        $response = $this->actingAs($this->admin())->get('/admin/stock?reorder=1');
        $response->assertStatus(200);

        $names = collect($response->viewData('page')['props']['products']['data'])->pluck('name');

        $this->assertContains('Running out', $names);
        $this->assertNotContains('Well stocked', $names);
    }

    public function test_the_reorder_level_can_be_edited_without_touching_stock(): void
    {
        $product = $this->product('Adjustable', 12, reorderLevel: 5);

        $this->actingAs($this->admin())->patchJson("/api/admin/products/{$product->id}", [
            'name' => 'Adjustable',
            'price' => 1000,
            'reorder_level' => 40,
        ])->assertStatus(200);

        $product = $product->fresh();

        $this->assertSame(40, $product->reorder_level);
        $this->assertSame(12, $product->stock_quantity, 'editing the reorder level moved stock');
        $this->assertTrue($product->needsReorder());
    }
}
