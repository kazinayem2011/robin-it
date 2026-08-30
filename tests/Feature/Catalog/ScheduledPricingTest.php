<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDiscount;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Discounts that start and stop by themselves, and prices that depend on how
 * many you buy.
 *
 * The hazard worth guarding is not the arithmetic. It is that a product's price
 * is computed twice — once in PHP to display it, once in SQL to sort and filter
 * by it — and the two have to agree. If they drift, a shopper filtering "under
 * 100,000" gets cards showing 125,000, and it reads as a broken filter rather
 * than a pricing bug.
 */
class ScheduledPricingTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'laptop'],
            ['name' => 'Laptop', 'is_active' => true]
        );

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Test Laptop',
            'slug' => 'test-laptop-'.uniqid(),
            'price' => 100000,
            'discount_price' => 80000,
            'stock_quantity' => 50,
            'is_active' => true,
        ], $attributes));
    }

    private function service(): ProductService
    {
        return app(ProductService::class);
    }

    // ------------------------------------------------------------- window

    public function test_a_discount_with_no_dates_runs_as_it_always_did(): void
    {
        $product = $this->product();

        $this->assertTrue($product->hasDiscount());
        $this->assertSame(80000.0, $product->effective_price);
    }

    public function test_a_discount_that_has_not_started_is_not_applied(): void
    {
        $product = $this->product(['discount_starts_at' => now()->addDay()]);

        $this->assertFalse($product->hasDiscount());
        $this->assertSame(100000.0, $product->effective_price);
    }

    public function test_a_discount_that_has_ended_is_not_applied(): void
    {
        $product = $this->product(['discount_ends_at' => now()->subMinute()]);

        $this->assertFalse($product->hasDiscount());
        $this->assertSame(100000.0, $product->effective_price);
    }

    public function test_a_discount_inside_its_window_is_applied(): void
    {
        $product = $this->product([
            'discount_starts_at' => now()->subDay(),
            'discount_ends_at' => now()->addDay(),
        ]);

        $this->assertTrue($product->hasDiscount());
        $this->assertSame(80000.0, $product->effective_price);
    }

    /**
     * The reason for scheduling at all: the sale has to stop on time whether or
     * not anyone is at a desk to clear the field.
     */
    public function test_a_sale_ends_on_its_own(): void
    {
        $product = $this->product(['discount_ends_at' => now()->addHour()]);

        $this->assertTrue($product->hasDiscount());

        Carbon::setTestNow(now()->addHours(2));

        $this->assertTrue($product->fresh()->hasDiscount() === false);
        $this->assertSame(100000.0, $product->fresh()->effective_price);

        Carbon::setTestNow();
    }

    // ------------------------------------------- PHP and SQL must agree

    /**
     * The one that matters. A price filter runs in SQL; the card renders from
     * PHP. An expired discount that SQL still believes in puts a product in a
     * bracket its displayed price is not in.
     */
    public function test_a_price_filter_uses_the_same_window_as_the_page(): void
    {
        $expired = $this->product([
            'name' => 'Expired Sale',
            'price' => 100000,
            'discount_price' => 40000,
            'discount_ends_at' => now()->subDay(),
        ]);

        // Filtering below the lapsed discount must not return it: it costs
        // 100,000 now, whatever the discount column still says.
        $results = $this->service()->getFilteredProducts(['max_price' => 50000]);

        $this->assertNotContains(
            $expired->id,
            $results->pluck('id')->all(),
            'SQL applied a discount the product page would not show.'
        );
    }

    public function test_a_live_sale_is_found_by_its_discounted_price(): void
    {
        $live = $this->product([
            'price' => 100000,
            'discount_price' => 40000,
            'discount_starts_at' => now()->subDay(),
            'discount_ends_at' => now()->addDay(),
        ]);

        $results = $this->service()->getFilteredProducts(['max_price' => 50000]);

        $this->assertContains($live->id, $results->pluck('id')->all());
    }

    public function test_sorting_by_price_uses_the_window_too(): void
    {
        $expired = $this->product([
            'name' => 'Expired', 'price' => 90000, 'discount_price' => 10000,
            'discount_ends_at' => now()->subDay(),
        ]);
        $live = $this->product([
            'name' => 'Live', 'price' => 95000, 'discount_price' => 20000,
        ]);

        $ordered = $this->service()
            ->getFilteredProducts(['sort' => 'price_low_high'])
            ->pluck('id')->all();

        // Live sells for 20,000; expired sells for 90,000. Cheapest first.
        $this->assertSame(
            [$live->id, $expired->id],
            array_values(array_intersect($ordered, [$live->id, $expired->id]))
        );
    }

    // ------------------------------------------------------ quantity tiers

    public function test_buying_more_reaches_a_cheaper_tier(): void
    {
        $product = $this->product(['price' => 200, 'discount_price' => null]);

        ProductDiscount::create(['product_id' => $product->id, 'min_quantity' => 10, 'price' => 180]);
        ProductDiscount::create(['product_id' => $product->id, 'min_quantity' => 50, 'price' => 160]);

        $this->assertSame(200.0, $product->priceForQuantity(1));
        $this->assertSame(200.0, $product->priceForQuantity(9));
        $this->assertSame(180.0, $product->priceForQuantity(10));
        $this->assertSame(180.0, $product->priceForQuantity(49));
        $this->assertSame(160.0, $product->priceForQuantity(50));
        $this->assertSame(160.0, $product->priceForQuantity(500));
    }

    /**
     * A standing trade rate must not charge a bulk buyer more than a passer-by
     * during a sharper site-wide sale.
     */
    public function test_a_tier_never_costs_more_than_the_ordinary_price(): void
    {
        $product = $this->product(['price' => 200, 'discount_price' => 100]);

        ProductDiscount::create(['product_id' => $product->id, 'min_quantity' => 10, 'price' => 180]);

        $this->assertSame(100.0, $product->priceForQuantity(20));
    }

    public function test_an_expired_tier_is_ignored(): void
    {
        $product = $this->product(['price' => 200, 'discount_price' => null]);

        ProductDiscount::create([
            'product_id' => $product->id, 'min_quantity' => 10,
            'price' => 180, 'ends_at' => now()->subDay(),
        ]);

        $this->assertSame(200.0, $product->priceForQuantity(20));
    }

    /**
     * The eager-loaded path and the query path have to produce the same figure.
     * The product page loads the tiers; the cart does not, and a cart charging
     * a different price from the page is the worst kind of pricing bug.
     */
    public function test_the_loaded_and_unloaded_paths_agree(): void
    {
        $product = $this->product(['price' => 200, 'discount_price' => null]);

        ProductDiscount::create(['product_id' => $product->id, 'min_quantity' => 10, 'price' => 180]);
        ProductDiscount::create([
            'product_id' => $product->id, 'min_quantity' => 20,
            'price' => 150, 'ends_at' => now()->subDay(),
        ]);

        $unloaded = Product::find($product->id);
        $loaded = Product::with('quantityDiscounts')->find($product->id);

        foreach ([1, 10, 25, 100] as $quantity) {
            $this->assertSame(
                $unloaded->priceForQuantity($quantity),
                $loaded->priceForQuantity($quantity),
                "Disagreed at quantity {$quantity}."
            );
        }
    }

    // -------------------------------------------------- minimum order size

    public function test_the_minimum_order_quantity_is_never_below_one(): void
    {
        $this->assertSame(1, $this->product(['min_order_quantity' => 0])->minimumOrderQuantity());
        $this->assertSame(1, $this->product()->minimumOrderQuantity());
        $this->assertSame(5, $this->product(['min_order_quantity' => 5])->minimumOrderQuantity());
    }

    // ---------------------------------------------------------- view count

    public function test_opening_a_product_counts_a_view_without_touching_updated_at(): void
    {
        $product = $this->product();
        $stamp = $product->updated_at;

        Carbon::setTestNow(now()->addHour());

        $this->service()->getProductBySlug($product->slug);
        $this->service()->getProductBySlug($product->slug);

        $product->refresh();

        $this->assertSame(2, $product->views_count);
        $this->assertTrue(
            $product->updated_at->eq($stamp),
            'A view is not an edit; stamping updated_at makes "recently changed" meaningless.'
        );

        Carbon::setTestNow();
    }
}
