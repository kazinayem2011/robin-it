<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a brand shelf holds, and who decides.
 *
 * The shop lists brands as categories — "ASUS" under Brand PC, with its own
 * page. Those shelves used to hold their products the way any shelf does: by
 * somebody filing each product twice, once under Brand PC and again under
 * ASUS. Miss the second and the product is absent from a page it plainly
 * belongs on, with nothing anywhere to say so.
 *
 * A brand shelf is now answered instead of stored.
 */
class BrandShelfListingTest extends TestCase
{
    use RefreshDatabase;

    private Category $root;

    private Category $parent;

    private Brand $asus;

    private Brand $msi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asus = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
        $this->msi = Brand::create(['name' => 'MSI', 'slug' => 'msi']);

        $this->root = Category::create(['name' => 'Desktop', 'slug' => 'desktop', 'is_active' => true]);
        $this->parent = Category::create([
            'name' => 'Brand PC', 'slug' => 'brand-pc',
            'parent_id' => $this->root->id, 'is_active' => true,
        ]);
    }

    private function shelfFor(Brand $brand, string $slug): Category
    {
        return Category::create([
            'name' => $brand->name, 'slug' => $slug,
            'parent_id' => $this->parent->id,
            'brand_id' => $brand->id, 'is_active' => true,
        ]);
    }

    /** Filed on the parent shelf only — never under the brand. */
    private function product(string $name, ?Brand $brand, ?Category $on = null): Product
    {
        $on ??= $this->parent;

        $product = Product::create([
            'name' => $name, 'slug' => str($name)->slug(),
            'category_id' => $on->id, 'brand_id' => $brand?->id,
            'price' => 1000, 'stock_quantity' => 5, 'is_active' => true,
        ]);

        $product->categories()->syncWithoutDetaching([$on->id]);

        return $product;
    }

    private function namesOn(string $slug): array
    {
        return collect(app(ProductService::class)->getFilteredProducts([
            'category_slug' => $slug,
        ])->items())->pluck('name')->sort()->values()->all();
    }

    public function test_a_brand_shelf_holds_what_its_maker_made_above_it(): void
    {
        $this->shelfFor($this->asus, 'asus-pc');
        $this->product('ASUS Tower', $this->asus);
        $this->product('MSI Tower', $this->msi);

        $this->assertSame(['ASUS Tower'], $this->namesOn('asus-pc'));
    }

    /** Nobody files anything twice any more. */
    public function test_it_needs_no_second_filing(): void
    {
        $shelf = $this->shelfFor($this->asus, 'asus-pc');
        $product = $this->product('ASUS Tower', $this->asus);

        $this->assertSame(
            0,
            $product->categories()->where('categories.id', $shelf->id)->count(),
            'the product was filed on the brand shelf, so this proves nothing',
        );
        $this->assertSame(['ASUS Tower'], $this->namesOn('asus-pc'));
    }

    /** A product that changes hands leaves the old maker's page by itself. */
    public function test_changing_a_product_brand_moves_it_between_shelves(): void
    {
        $this->shelfFor($this->asus, 'asus-pc');
        $this->shelfFor($this->msi, 'msi-pc');
        $product = $this->product('Tower', $this->asus);

        $this->assertSame(['Tower'], $this->namesOn('asus-pc'));

        $product->update(['brand_id' => $this->msi->id]);

        $this->assertSame([], $this->namesOn('asus-pc'));
        $this->assertSame(['Tower'], $this->namesOn('msi-pc'));
    }

    /**
     * Bounded by the shelf above, not the whole shop. An ASUS shelf under
     * Brand PC is ASUS desktops — the ASUS monitor belongs to the ASUS shelf
     * under Monitor, and showing it here would make the two pages the same.
     */
    public function test_it_does_not_reach_outside_its_own_parent(): void
    {
        $this->shelfFor($this->asus, 'asus-pc');
        $this->product('ASUS Tower', $this->asus);

        $monitors = Category::create(['name' => 'Monitor', 'slug' => 'monitor', 'is_active' => true]);
        $this->product('ASUS Monitor', $this->asus, $monitors);

        $this->assertSame(['ASUS Tower'], $this->namesOn('asus-pc'));
    }

    /** An ordinary shelf is unchanged: it holds what was filed on it. */
    public function test_a_shelf_with_no_brand_still_holds_what_it_was_given(): void
    {
        $line = Category::create([
            'name' => 'Gaming PC', 'slug' => 'gaming-pc',
            'parent_id' => $this->parent->id, 'is_active' => true,
        ]);

        $this->product('Gaming Rig', $this->msi, $line);
        $this->product('Office Box', $this->asus);

        $this->assertSame(['Gaming Rig'], $this->namesOn('gaming-pc'));
    }

    /**
     * The menu has to carry it. It holds no pivot rows by design, and the
     * menu drops empty shelves — so the one page that fills itself would
     * otherwise be the one page nobody could reach.
     */
    public function test_the_menu_carries_a_shelf_that_holds_only_derived_products(): void
    {
        $shelf = $this->shelfFor($this->asus, 'asus-pc');
        $this->product('ASUS Tower', $this->asus);

        $ids = collect(app(CategoryService::class)->getMegaMenuTree())
            ->flatMap(fn ($top) => collect($top['subcategories'])->flatMap(fn ($s) => $s['children']))
            ->pluck('id');

        $this->assertContains($shelf->id, $ids->all());
    }

    /** And drops it when the maker has nothing on the shelf above. */
    public function test_the_menu_drops_a_brand_shelf_with_nothing_to_show(): void
    {
        $shelf = $this->shelfFor($this->asus, 'asus-pc');
        $this->product('MSI Tower', $this->msi);

        $ids = collect(app(CategoryService::class)->getMegaMenuTree())
            ->flatMap(fn ($top) => collect($top['subcategories'])->flatMap(fn ($s) => $s['children']))
            ->pluck('id');

        $this->assertNotContains($shelf->id, $ids->all());
    }
}
