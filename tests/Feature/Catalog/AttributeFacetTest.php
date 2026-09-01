<?php

namespace Tests\Feature\Catalog;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Filtering by what a product is, not only by price and brand.
 *
 * The shop offered four filters and none described the product. The spec sheet
 * could not become the fifth: its values are prose for one reader — "8 (4
 * Performance cores, 4 Efficient cores)" — so filtering on that column would
 * make every distinct string its own checkbox.
 *
 * These are curated values instead, declared per shelf, and the rules here are
 * the ones every shop's sidebar follows and which are easy to get subtly wrong.
 */
class AttributeFacetTest extends TestCase
{
    use RefreshDatabase;

    private Category $aisle;

    private Category $shelf;

    private Attribute $standard;

    private Attribute $bands;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aisle = Category::create(['name' => 'Router', 'slug' => 'router', 'is_active' => true]);
        $this->shelf = Category::create([
            'name' => 'TP-Link', 'slug' => 'router-tp-link',
            'parent_id' => $this->aisle->id, 'is_active' => true,
        ]);

        $this->standard = $this->attribute('Wi-Fi Standard', ['Wi-Fi 6', 'Wi-Fi 5']);
        $this->bands = $this->attribute('Number of Bands', ['Dual Band', 'Tri-Band']);
    }

    private function attribute(string $name, array $labels): Attribute
    {
        $attribute = Attribute::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'input_type' => Attribute::ENUM,
        ]);

        foreach ($labels as $i => $label) {
            AttributeValue::create([
                'attribute_id' => $attribute->id,
                'label' => $label,
                'slug' => Str::slug($label),
                'sort_order' => $i,
            ]);
        }

        // Declared on the aisle, so every shelf beneath inherits it.
        $attribute->categories()->attach($this->aisle->id);

        return $attribute;
    }

    private function product(string $name, array $valueSlugs): Product
    {
        $product = Product::create([
            'category_id' => $this->shelf->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => 5000,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);

        $product->attributeValues()->sync(
            AttributeValue::whereIn('slug', $valueSlugs)->pluck('id')
        );

        return $product;
    }

    private function service(): ProductService
    {
        return app(ProductService::class);
    }

    private function facetFor(string $slug, array $filters): ?array
    {
        $facets = $this->service()->getFilterFacets($filters + ['category_slug' => 'router']);

        return collect($facets['attributes'])->firstWhere('slug', $slug);
    }

    public function test_a_shelf_offers_the_questions_its_aisle_declares(): void
    {
        $this->product('Archer AX10', ['wi-fi-6', 'dual-band']);

        $names = collect($this->service()->getFilterFacets(['category_slug' => 'router-tp-link'])['attributes'])
            ->pluck('name')
            ->all();

        $this->assertEqualsCanonicalizing(['Wi-Fi Standard', 'Number of Bands'], $names);
    }

    public function test_each_answer_carries_how_many_products_give_it(): void
    {
        $this->product('A', ['wi-fi-6']);
        $this->product('B', ['wi-fi-6']);
        $this->product('C', ['wi-fi-5']);

        $counts = collect($this->facetFor('wi-fi-standard', [])['values'])
            ->pluck('count', 'slug')
            ->all();

        $this->assertSame(['wi-fi-6' => 2, 'wi-fi-5' => 1], $counts);
    }

    /** An answer nothing gives is a dead end, not a filter. */
    public function test_an_answer_no_product_gives_is_not_offered(): void
    {
        $this->product('A', ['wi-fi-6']);

        $slugs = collect($this->facetFor('wi-fi-standard', [])['values'])->pluck('slug')->all();

        $this->assertSame(['wi-fi-6'], $slugs);
    }

    /** Two answers to the same question widen the list. */
    public function test_choosing_two_values_of_one_attribute_is_an_or(): void
    {
        $this->product('A', ['wi-fi-6']);
        $this->product('B', ['wi-fi-5']);

        $found = $this->service()->getFilteredProducts([
            'category_slug' => 'router',
            'attributes' => ['wi-fi-standard' => ['wi-fi-6', 'wi-fi-5']],
        ]);

        $this->assertCount(2, $found);
    }

    /** Answers to different questions narrow it. */
    public function test_choosing_across_attributes_is_an_and(): void
    {
        $this->product('A', ['wi-fi-6', 'dual-band']);
        $this->product('B', ['wi-fi-6', 'tri-band']);

        $found = $this->service()->getFilteredProducts([
            'category_slug' => 'router',
            'attributes' => [
                'wi-fi-standard' => ['wi-fi-6'],
                'number-of-bands' => ['dual-band'],
            ],
        ]);

        $this->assertCount(1, $found);
        $this->assertSame('A', $found->first()->name);
    }

    /**
     * The rule that is easy to get wrong: an attribute's own counts ignore its
     * own selection. Narrowing Wi-Fi Standard to the standard already ticked
     * would leave nothing else to tick, and the shopper could never widen.
     */
    public function test_an_attribute_does_not_narrow_its_own_options(): void
    {
        $this->product('A', ['wi-fi-6']);
        $this->product('B', ['wi-fi-5']);

        $facet = $this->facetFor('wi-fi-standard', [
            'attributes' => ['wi-fi-standard' => ['wi-fi-6']],
        ]);

        $this->assertCount(2, $facet['values'], 'Wi-Fi 5 stopped being offered.');
    }

    /** But every other question does narrow, or the counts would be lies. */
    public function test_another_attribute_does_narrow_to_the_selection(): void
    {
        $this->product('A', ['wi-fi-6', 'dual-band']);
        $this->product('B', ['wi-fi-5', 'tri-band']);

        $facet = $this->facetFor('number-of-bands', [
            'attributes' => ['wi-fi-standard' => ['wi-fi-6']],
        ]);

        $this->assertSame(
            ['dual-band' => 1],
            collect($facet['values'])->pluck('count', 'slug')->all()
        );
    }

    public function test_a_category_with_no_questions_offers_none(): void
    {
        $other = Category::create(['name' => 'Mouse', 'slug' => 'mouse', 'is_active' => true]);
        Product::create([
            'category_id' => $other->id, 'name' => 'MX', 'slug' => 'mx',
            'price' => 100, 'stock_quantity' => 1, 'is_active' => true,
        ]);

        $facets = $this->service()->getFilterFacets(['category_slug' => 'mouse']);

        $this->assertSame([], $facets['attributes']);
    }

    /** A hand-typed address must not be a way to see everything. */
    public function test_an_invented_value_matches_nothing(): void
    {
        $this->product('A', ['wi-fi-6']);

        $found = $this->service()->getFilteredProducts([
            'category_slug' => 'router',
            'attributes' => ['wi-fi-standard' => ['wi-fi-9000']],
        ]);

        $this->assertCount(0, $found);
    }

    /** A band is a value like any other, with its bounds kept for placing. */
    public function test_a_numeric_band_knows_what_it_covers(): void
    {
        $speed = Attribute::create([
            'name' => 'WiFi Speed', 'slug' => 'wifi-speed',
            'input_type' => Attribute::NUMBER, 'unit' => 'Mbps',
        ]);

        foreach ([['Up to 300 Mbps', null, 300], ['301 Mbps to 750 Mbps', 301, 750]] as $i => [$l, $from, $to]) {
            AttributeValue::create([
                'attribute_id' => $speed->id, 'label' => $l, 'slug' => Str::slug($l),
                'range_from' => $from, 'range_to' => $to, 'sort_order' => $i,
            ]);
        }

        $speed->load('values');

        $this->assertSame('Up to 300 Mbps', $speed->bandFor(120)->label);
        $this->assertSame('301 Mbps to 750 Mbps', $speed->bandFor(600)->label);
        $this->assertNull($speed->bandFor(9000), 'A speed above every band was placed in one.');
    }
}
