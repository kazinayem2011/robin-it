<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the sidebar's category panel offers, and in what order.
 *
 * It listed every department on every page — standing in Laptop the first
 * thing in the panel was Accessories, then Camera, then Component — which is
 * the mega menu again, in a panel meant for narrowing rather than leaving, and
 * it pushed Price, Brand and the spec filters below the fold to do it.
 *
 * It was also alphabetical, so it ignored the order an admin had arranged and
 * that every other list on the storefront follows.
 */
class CategoryFacetScopeTest extends TestCase
{
    use RefreshDatabase;

    private function dept(string $name, int $position): Category
    {
        return Category::create([
            'name' => $name, 'slug' => str($name)->slug(),
            'position' => $position, 'is_active' => true,
        ]);
    }

    private function shelf(string $name, Category $parent, int $position, ?Brand $brand = null): Category
    {
        return Category::create([
            'name' => $name, 'slug' => str($name)->slug(),
            'parent_id' => $parent->id, 'position' => $position,
            'brand_id' => $brand?->id, 'is_active' => true,
        ]);
    }

    private function stock(Category $on, ?Brand $brand = null): Product
    {
        $product = Product::create([
            'name' => $on->name.' item', 'slug' => $on->slug.'-item',
            'category_id' => $on->id, 'brand_id' => $brand?->id,
            'price' => 1000, 'stock_quantity' => 4, 'is_active' => true,
        ]);
        $product->categories()->syncWithoutDetaching([$on->id]);

        return $product;
    }

    private function facet(array $filters = []): array
    {
        return app(ProductService::class)->getFilterFacets($filters)['categories'];
    }

    public function test_standing_in_a_department_it_offers_only_that_one(): void
    {
        $laptop = $this->dept('Laptop', 0);
        $this->stock($this->shelf('Gaming Laptop', $laptop, 0));

        $desktop = $this->dept('Desktop', 1);
        $this->stock($this->shelf('Brand PC', $desktop, 0));

        $rows = $this->facet(['category_slug' => 'laptop']);

        $this->assertSame(['Laptop'], collect($rows)->pluck('name')->all());
    }

    /** With no department chosen, choosing one is the point of the panel. */
    public function test_with_nothing_chosen_it_offers_them_all(): void
    {
        $laptop = $this->dept('Laptop', 0);
        $this->stock($this->shelf('Gaming Laptop', $laptop, 0));
        $desktop = $this->dept('Desktop', 1);
        $this->stock($this->shelf('Brand PC', $desktop, 0));

        $this->assertSame(['Laptop', 'Desktop'], collect($this->facet())->pluck('name')->all());
    }

    /**
     * The shop's order, not the alphabet. Zebra is arranged first and belongs
     * first; sorting by name would put Alpha there and silently overrule the
     * person who arranged it.
     */
    public function test_it_follows_the_shop_order_not_the_alphabet(): void
    {
        $zebra = $this->dept('Zebra', 0);
        $this->stock($this->shelf('Z child', $zebra, 0));
        $alpha = $this->dept('Alpha', 1);
        $this->stock($this->shelf('A child', $alpha, 0));

        $this->assertSame(['Zebra', 'Alpha'], collect($this->facet())->pluck('name')->all());
    }

    public function test_the_shelves_inside_follow_it_too(): void
    {
        $laptop = $this->dept('Laptop', 0);
        $this->stock($this->shelf('Zebra Laptop', $laptop, 0));
        $this->stock($this->shelf('Alpha Laptop', $laptop, 1));

        $rows = $this->facet(['category_slug' => 'laptop']);

        $this->assertSame(
            ['Zebra Laptop', 'Alpha Laptop'],
            collect($rows[0]['children'])->pluck('name')->all(),
        );
    }

    /** Siblings stay reachable — that is what makes it navigation, not a wall. */
    public function test_it_keeps_the_other_shelves_in_the_same_department(): void
    {
        $laptop = $this->dept('Laptop', 0);
        $this->stock($this->shelf('All Laptop', $laptop, 0));
        $this->stock($this->shelf('Gaming Laptop', $laptop, 1));

        $rows = $this->facet(['category_slug' => 'gaming-laptop']);

        $this->assertSame(
            ['All Laptop', 'Gaming Laptop'],
            collect($rows[0]['children'])->pluck('name')->all(),
        );
    }

    /**
     * From a brand shelf, three levels down, back up to its department —
     * walked rather than assumed, since a series can sit deeper still.
     */
    public function test_it_finds_the_department_from_a_brand_shelf(): void
    {
        $brand = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
        $laptop = $this->dept('Laptop', 0);
        $all = $this->shelf('All Laptop', $laptop, 0);
        $this->shelf('ASUS', $all, 0, $brand);
        $this->stock($all, $brand);

        /*
         * Stocked, deliberately. An empty department is dropped by the count
         * check anyway, so without this the assertion below would hold even
         * if the walk stopped short of the department and narrowed nothing.
         */
        $desktop = $this->dept('Desktop', 1);
        $this->stock($this->shelf('Brand PC', $desktop, 0));

        $rows = $this->facet(['category_slug' => 'asus']);

        $this->assertSame(['Laptop'], collect($rows)->pluck('name')->all());
    }

    /** A slug that names nothing narrows nothing, rather than emptying it. */
    public function test_an_unknown_slug_leaves_the_panel_whole(): void
    {
        $laptop = $this->dept('Laptop', 0);
        $this->stock($this->shelf('Gaming Laptop', $laptop, 0));

        $this->assertSame(
            ['Laptop'],
            collect($this->facet(['category_slug' => 'no-such-shelf']))->pluck('name')->all(),
        );
    }
}
