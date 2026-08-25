<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The categories the filter sidebar offers.
 *
 * The sidebar had no category filter at all — a shopper could narrow by price,
 * brand and stock, but the only way to change category was the top navigation.
 */
class CategoryFacetTest extends TestCase
{
    use RefreshDatabase;

    private Category $components;

    private Category $gpus;

    private Category $cpus;

    private Brand $asus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->components = Category::create(['name' => 'Components', 'slug' => 'components', 'is_active' => true]);
        $this->gpus = Category::create(['name' => 'Graphics Cards', 'slug' => 'gpu', 'parent_id' => $this->components->id, 'is_active' => true]);
        $this->cpus = Category::create(['name' => 'Processors', 'slug' => 'cpu', 'parent_id' => $this->components->id, 'is_active' => true]);
        $this->asus = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
    }

    private function product(Category $category, string $name, float $price = 50000): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'brand_id' => $this->asus->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => $price,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);
    }

    private function facets(array $filters = []): array
    {
        return app(ProductService::class)->getFilterFacets($filters);
    }

    public function test_the_sidebar_is_offered_the_categories_that_have_products(): void
    {
        $this->product($this->gpus, 'RTX 5090');
        $this->product($this->cpus, 'Core i9');

        $categories = $this->facets()['categories'];

        $this->assertCount(1, $categories, 'one top-level category holds both');
        $this->assertSame('Components', $categories[0]['name']);
        $this->assertEqualsCanonicalizing(
            ['Graphics Cards', 'Processors'],
            array_column($categories[0]['children'], 'name')
        );
    }

    /**
     * Products hang off leaf categories, so a parent showing nothing would be
     * a link that plainly returns results.
     */
    public function test_a_parent_counts_everything_beneath_it(): void
    {
        $this->product($this->gpus, 'RTX 5090');
        $this->product($this->gpus, 'RX 9070');
        $this->product($this->cpus, 'Core i9');

        $components = $this->facets()['categories'][0];

        $this->assertSame(3, $components['count']);
        $this->assertSame(
            2,
            collect($components['children'])->firstWhere('name', 'Graphics Cards')['count']
        );
    }

    public function test_an_empty_category_is_not_offered(): void
    {
        Category::create(['name' => 'Drones', 'slug' => 'drones', 'is_active' => true]);
        $this->product($this->gpus, 'RTX 5090');

        $names = array_column($this->facets()['categories'], 'name');

        $this->assertNotContains('Drones', $names);
    }

    /**
     * The same rule the brand list follows: narrowing the list to the category
     * already chosen would leave nothing else to pick.
     */
    public function test_choosing_a_category_still_offers_the_others(): void
    {
        $this->product($this->gpus, 'RTX 5090');
        $this->product($this->cpus, 'Core i9');

        $categories = $this->facets(['category_slug' => 'gpu'])['categories'];

        $this->assertEqualsCanonicalizing(
            ['Graphics Cards', 'Processors'],
            array_column($categories[0]['children'], 'name')
        );
    }

    /** Every other filter still applies, so the counts never promise too much. */
    public function test_the_counts_respect_the_other_filters(): void
    {
        $this->product($this->gpus, 'Cheap card', 10000);
        $this->product($this->gpus, 'Costly card', 200000);
        $this->product($this->cpus, 'Cheap chip', 12000);

        $categories = $this->facets(['in_stock' => true, 'search' => 'card'])['categories'];

        $this->assertSame(2, $categories[0]['count']);
        $this->assertCount(1, $categories[0]['children']);
        $this->assertSame('Graphics Cards', $categories[0]['children'][0]['name']);
    }

    public function test_an_empty_catalogue_offers_nothing_rather_than_failing(): void
    {
        $this->assertSame([], $this->facets()['categories']);
    }

    public function test_the_endpoint_serves_them(): void
    {
        $this->product($this->gpus, 'RTX 5090');

        $response = $this->getJson('/api/products/filters');

        $response->assertStatus(200);
        $this->assertSame('Components', $response->json('data.categories.0.name'));
    }
}
