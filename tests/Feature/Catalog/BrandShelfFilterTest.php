<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A brand shelf has already said which maker.
 *
 * So the sidebar offers no Brand filter there — it would be one checkbox that
 * changes nothing — and any brand that arrives anyway, from an old link or a
 * pasted URL, must not quietly contradict the shelf and empty the grid with
 * nothing on screen to explain it.
 */
class BrandShelfFilterTest extends TestCase
{
    use RefreshDatabase;

    private Brand $asus;

    private Brand $msi;

    private Category $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asus = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
        $this->msi = Brand::create(['name' => 'MSI', 'slug' => 'msi']);

        $root = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $this->parent = Category::create([
            'name' => 'All Laptop', 'slug' => 'all-laptop',
            'parent_id' => $root->id, 'is_active' => true,
        ]);

        Category::create([
            'name' => 'ASUS', 'slug' => 'asus-all-laptop',
            'parent_id' => $this->parent->id,
            'brand_id' => $this->asus->id, 'is_active' => true,
        ]);

        foreach ([[$this->asus, 'ASUS Book'], [$this->msi, 'MSI Book']] as [$brand, $name]) {
            $product = Product::create([
                'name' => $name, 'slug' => str($name)->slug(),
                'category_id' => $this->parent->id, 'brand_id' => $brand->id,
                'price' => 1000, 'stock_quantity' => 4, 'is_active' => true,
            ]);
            $product->categories()->syncWithoutDetaching([$this->parent->id]);
        }
    }

    private function facets(string $slug): array
    {
        return app(ProductService::class)->getFilterFacets(['category_slug' => $slug]);
    }

    private function names(array $filters): array
    {
        return collect(app(ProductService::class)->getFilteredProducts($filters)->items())
            ->pluck('name')->sort()->values()->all();
    }

    public function test_an_ordinary_shelf_says_it_is_not_a_brand_one(): void
    {
        $this->assertFalse($this->facets('all-laptop')['category']['is_brand_shelf']);
    }

    public function test_a_brand_shelf_says_so(): void
    {
        $this->assertTrue($this->facets('asus-all-laptop')['category']['is_brand_shelf']);
    }

    /** Which is what the sidebar needs, because there is only ever one there. */
    public function test_a_brand_shelf_has_only_its_own_maker_to_offer(): void
    {
        $this->assertSame(
            ['ASUS'],
            collect($this->facets('asus-all-laptop')['brands'])->pluck('name')->all(),
        );
    }

    public function test_an_ordinary_shelf_offers_every_maker_on_it(): void
    {
        $this->assertSame(
            ['ASUS', 'MSI'],
            collect($this->facets('all-laptop')['brands'])->pluck('name')->sort()->values()->all(),
        );
    }

    /**
     * The trap this closes. A brand filter reaching a brand shelf would AND
     * with the shelf's own maker and match nothing — an empty grid with no
     * filter on screen to account for it.
     */
    public function test_a_brand_from_the_url_cannot_contradict_the_shelf(): void
    {
        $this->assertSame(
            ['ASUS Book'],
            $this->names([
                'category_slug' => 'asus-all-laptop',
                'brand_ids' => [$this->msi->id],
            ]),
        );
    }

    public function test_the_same_by_slug_or_by_single_id(): void
    {
        foreach ([['brand_slug' => 'msi'], ['brand_id' => $this->msi->id]] as $filter) {
            $this->assertSame(
                ['ASUS Book'],
                $this->names(['category_slug' => 'asus-all-laptop'] + $filter),
            );
        }
    }

    /** And on an ordinary shelf a brand filter still does its job. */
    public function test_a_brand_filter_still_narrows_an_ordinary_shelf(): void
    {
        $this->assertSame(
            ['MSI Book'],
            $this->names([
                'category_slug' => 'all-laptop',
                'brand_ids' => [$this->msi->id],
            ]),
        );
    }
}
