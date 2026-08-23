<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Narrowing the catalogue.
 *
 * The price filters measure the price a shopper actually pays, not the list
 * price: a card at 265,000 marked down to 245,000 belongs in the bracket it
 * visibly sits in, not the one it used to.
 */
class ProductFilteringTest extends TestCase
{
    use RefreshDatabase;

    private Category $gpus;

    private Brand $asus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gpus = Category::create(['name' => 'Graphics Cards', 'slug' => 'gpu', 'is_active' => true]);
        $this->asus = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
    }

    private function product(string $name, float $price, ?float $discount = null, int $stock = 5, ?Brand $brand = null): Product
    {
        $product = Product::create([
            'category_id' => $this->gpus->id,
            'brand_id' => ($brand ?? $this->asus)->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => $price,
            'discount_price' => $discount,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        if ($stock > 0) {
            app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => $stock]]);
        }

        return $product->fresh();
    }

    private function names(array $query): array
    {
        $response = $this->getJson('/api/products?'.http_build_query($query));
        $response->assertStatus(200);

        return collect($response->json('data'))->pluck('name')->sort()->values()->all();
    }

    public function test_the_price_range_uses_the_price_actually_paid(): void
    {
        $this->product('Discounted card', 265000, 90000);
        $this->product('Full price card', 95000);

        // Both land under 100,000 once the discount is taken into account.
        $this->assertSame(
            ['Discounted card', 'Full price card'],
            $this->names(['max_price' => 100000])
        );
    }

    public function test_a_discounted_product_is_not_judged_by_its_old_price(): void
    {
        $this->product('Was expensive', 265000, 45000);

        // Filtering the top bracket must not catch it on the list price.
        $this->assertSame([], $this->names(['min_price' => 200000]));
        $this->assertSame(['Was expensive'], $this->names(['max_price' => 50000]));
    }

    public function test_a_zero_discount_does_not_make_something_free(): void
    {
        // discount_price of 0 is "no discount", not "costs nothing".
        $this->product('Not free', 50000, 0);

        $this->assertSame([], $this->names(['max_price' => 100]));
        $this->assertSame(['Not free'], $this->names(['min_price' => 49999]));
    }

    public function test_both_bounds_together_form_a_bracket(): void
    {
        $this->product('Cheap', 15000);
        $this->product('Mid', 85000);
        $this->product('Dear', 250000);

        $this->assertSame(['Mid'], $this->names(['min_price' => 50000, 'max_price' => 100000]));
    }

    public function test_the_sale_filter_only_returns_real_discounts(): void
    {
        $this->product('Genuinely reduced', 100000, 80000);
        $this->product('No discount', 100000);
        // A "discount" above the list price is not a discount.
        $this->product('Nonsense discount', 100000, 120000);

        $this->assertSame(['Genuinely reduced'], $this->names(['on_sale' => 1]));
    }

    public function test_in_stock_hides_what_cannot_be_bought(): void
    {
        $this->product('Available', 50000, stock: 3);
        $this->product('Sold out', 50000, stock: 0);

        $this->assertSame(['Available'], $this->names(['in_stock' => 1]));
        $this->assertSame(['Available', 'Sold out'], $this->names([]));
    }

    public function test_filters_combine_rather_than_replace_each_other(): void
    {
        $msi = Brand::create(['name' => 'MSI', 'slug' => 'msi']);

        $this->product('ASUS in range', 60000);
        $this->product('ASUS too dear', 300000);
        $this->product('MSI in range', 60000, brand: $msi);

        $this->assertSame(
            ['ASUS in range'],
            $this->names(['brand_slug' => 'asus', 'max_price' => 100000])
        );
    }

    public function test_the_facets_describe_the_current_selection(): void
    {
        $msi = Brand::create(['name' => 'MSI', 'slug' => 'msi']);
        $this->product('Cheapest', 15000);
        $this->product('Dearest', 250000, 200000);
        $this->product('An MSI one', 90000, brand: $msi);

        $response = $this->getJson('/api/products/filters');
        $response->assertStatus(200);

        $facets = $response->json('data');

        $this->assertEqualsWithDelta(15000.0, $facets['min_price'], 0.01);
        // Bounded by the discounted price, not the 250,000 list price.
        $this->assertEqualsWithDelta(200000.0, $facets['max_price'], 0.01);
        $this->assertSame(3, $facets['total']);
        $this->assertSame(['ASUS', 'MSI'], collect($facets['brands'])->pluck('name')->all());
    }

    /**
     * The bounds must not move as the shopper drags the slider, or the control
     * fights them.
     */
    public function test_the_facet_range_ignores_the_shopper_s_own_price_bounds(): void
    {
        $this->product('Cheapest', 15000);
        $this->product('Dearest', 250000);

        $facets = $this->getJson('/api/products/filters?min_price=100000')->json('data');

        $this->assertEqualsWithDelta(15000.0, $facets['min_price'], 0.01);
        $this->assertEqualsWithDelta(250000.0, $facets['max_price'], 0.01);
    }

    public function test_the_facets_narrow_with_the_category(): void
    {
        $cpus = Category::create(['name' => 'Processors', 'slug' => 'cpu', 'is_active' => true]);
        $this->product('A card', 90000);

        Product::create([
            'category_id' => $cpus->id, 'brand_id' => $this->asus->id,
            'name' => 'A processor', 'slug' => 'a-processor',
            'price' => 45000, 'stock_quantity' => 2, 'is_active' => true,
        ]);

        $facets = $this->getJson('/api/products/filters?category_slug=gpu')->json('data');

        $this->assertSame(1, $facets['total']);
        $this->assertEqualsWithDelta(90000.0, $facets['min_price'], 0.01);
    }

    public function test_an_empty_selection_reports_a_zero_range_rather_than_failing(): void
    {
        $facets = $this->getJson('/api/products/filters?search=nothingmatchesthis')->json('data');

        $this->assertSame(0, $facets['total']);
        $this->assertEqualsWithDelta(0.0, $facets['max_price'], 0.01);
        $this->assertSame([], $facets['brands']);
    }
}
