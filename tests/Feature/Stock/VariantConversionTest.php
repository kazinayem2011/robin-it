<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switching a product between a single stock pool and per-option stock.
 *
 * A product's structure is only changeable while it has no history — no stock
 * movement, no order line. Once either exists the shape is fixed, which means a
 * conversion can never move units: there are none to move. What these cover is
 * that the switch is clean while it is still allowed, and firmly refused after.
 *
 * The refusal itself lives in ProductStructureLockTest.
 */
class VariantConversionTest extends TestCase
{
    use RefreshDatabase;

    /** A product with no history: the only kind that can still be restructured. */
    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Kingston Fury Beast',
            'slug' => 'fury-'.uniqid(),
            'price' => 4200,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    private function variants(): ProductVariantService
    {
        return app(ProductVariantService::class);
    }

    private function totalHolding(Product $product): int
    {
        $product = $product->fresh();

        return $product->has_variants
            ? (int) ProductVariant::where('product_id', $product->id)->sum('stock_quantity')
            : (int) $product->stock_quantity;
    }

    /** Convert while empty, then buy stock into each option. */
    private function withOptions(Product $product, int $small = 5, int $large = 7): Product
    {
        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 0],
            ['options' => ['Capacity' => '32GB'], 'price' => 8200, 'opening_stock' => 0],
        ]);

        $fresh = $product->fresh('variants');

        app(StockService::class)->receive([], array_values(array_filter([
            $small > 0 ? ['product_id' => $product->id, 'product_variant_id' => $fresh->variants->firstWhere('name', '16GB')->id, 'quantity' => $small] : null,
            $large > 0 ? ['product_id' => $product->id, 'product_variant_id' => $fresh->variants->firstWhere('name', '32GB')->id, 'quantity' => $large] : null,
        ])));

        return $product->fresh('variants');
    }

    public function test_a_fresh_product_can_be_given_options(): void
    {
        $product = $this->withOptions($this->product());

        $this->assertTrue($product->has_variants);
        $this->assertSame(12, $this->totalHolding($product));
        $this->assertSame(12, $product->stock_quantity, 'the parent total is not the sum of its options');

        $this->assertSame(5, $product->variants->firstWhere('name', '16GB')->stock_quantity);
        $this->assertSame(7, $product->variants->firstWhere('name', '32GB')->stock_quantity);
    }

    /**
     * Because only an empty product can be restructured, the switch itself has
     * nothing to carry across and must leave the ledger untouched.
     */
    public function test_the_switch_itself_writes_nothing_to_the_ledger(): void
    {
        $product = $this->product();

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 0],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 0],
        ]);

        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count(),
            'restructuring an empty product touched the ledger');
        $this->assertSame(0, $this->totalHolding($product));
    }

    /**
     * Opening stock would be units appearing without a purchase behind them.
     * The only honest opening balance on an empty product is zero.
     */
    public function test_opening_stock_cannot_be_invented_during_the_switch(): void
    {
        $product = $this->product();

        $this->expectExceptionMessage('has 0 in stock but you have allocated 20');

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 20],
        ]);
    }

    public function test_an_empty_variant_product_can_collapse_back(): void
    {
        $product = $this->product();

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 0],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 0],
        ]);

        $this->variants()->convertToSingle($product->fresh());

        $product = $product->fresh();
        $this->assertFalse($product->has_variants);
        $this->assertSame(0, $product->stock_quantity);
        $this->assertFalse(app(StockService::class)->verify($product)['drifted']);
    }

    /** Undoing a mis-click is fine; it is only history that fixes the shape. */
    public function test_a_round_trip_is_possible_while_the_product_is_untouched(): void
    {
        $product = $this->product();

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 0],
        ]);
        $this->variants()->convertToSingle($product->fresh());
        $this->variants()->convertToVariants($product->fresh(), ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 0],
        ]);

        $this->assertTrue($product->fresh()->has_variants);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    /**
     * The old rule only blocked conversion while an order was still open, so a
     * delivered order left the shape editable and the paperwork pointing at a
     * shelf that no longer existed. Any order line now fixes it for good.
     */
    public function test_a_product_that_has_been_ordered_can_never_be_restructured(): void
    {
        $product = $this->product();
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 12]]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->expectExceptionMessage('it appears on past orders');

        $this->variants()->convertToVariants($product->fresh(), ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 0],
        ]);
    }

    /** Editing labels and prices is not a stock operation. */
    public function test_editing_options_never_moves_stock(): void
    {
        $product = $this->withOptions($this->product());
        $first = $product->variants->firstWhere('name', '16GB');
        $second = $product->variants->firstWhere('name', '32GB');

        $this->variants()->syncVariants($product, ['Capacity'], [
            ['id' => $first->id, 'options' => ['Capacity' => '16GB'], 'price' => 4500, 'stock_quantity' => 999],
            ['id' => $second->id, 'options' => ['Capacity' => '32GB'], 'price' => 8200],
        ]);

        $this->assertSame(5, $first->fresh()->stock_quantity, 'editing an option moved its stock');
        $this->assertSame(7, $second->fresh()->stock_quantity);
        $this->assertEqualsWithDelta(4500.0, $first->fresh()->price, 0.01);
        $this->assertSame(12, $this->totalHolding($product));
    }

    /**
     * Deleting an option that holds units would destroy stock that is physically
     * on the shelf, so it is retired instead and its units stay accounted for.
     */
    public function test_removing_an_option_that_holds_stock_retires_it_instead(): void
    {
        $product = $this->withOptions($this->product());
        $keep = $product->variants->firstWhere('name', '16GB');
        $drop = $product->variants->firstWhere('name', '32GB');

        $this->variants()->syncVariants($product, ['Capacity'], [
            ['id' => $keep->id, 'options' => ['Capacity' => '16GB']],
        ]);

        $this->assertNotNull($drop->fresh(), 'an option holding stock was deleted');
        $this->assertFalse($drop->fresh()->is_active);
        $this->assertSame(7, $drop->fresh()->stock_quantity, 'its units vanished');
    }
}
