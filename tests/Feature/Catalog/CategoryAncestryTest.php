<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Naming a shelf where the name alone does not identify one.
 *
 * The tree repeats names by design: SteelSeries is six shelves, one under each
 * kind of thing it makes, and Asus is four. So anywhere a shelf is named on
 * its own — a search suggestion, a breadcrumb — the shopper is being asked to
 * choose between things they have not been told apart.
 */
class CategoryAncestryTest extends TestCase
{
    use RefreshDatabase;

    /** Accessories › Headphone › SteelSeries, three deep like the real tree. */
    private function shelf(string $leaf = 'SteelSeries'): Category
    {
        $root = Category::firstOrCreate(
            ['slug' => 'accessories'],
            ['name' => 'Accessories', 'is_active' => true],
        );
        $mid = Category::firstOrCreate(
            ['slug' => 'headphone'],
            ['name' => 'Headphone', 'parent_id' => $root->id, 'is_active' => true],
        );

        return Category::create([
            'name' => $leaf,
            'slug' => str($leaf)->slug()->value().'-headphone',
            'parent_id' => $mid->id,
            'is_active' => true,
        ]);
    }

    private function product(Category $shelf): Product
    {
        return Product::create([
            'category_id' => $shelf->id,
            'name' => 'Arctis Nova 7',
            'slug' => 'arctis-nova-7',
            'price' => 24000,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);
    }

    // --- the search suggestions --------------------------------------------

    public function test_a_suggested_shelf_carries_its_ancestry(): void
    {
        $this->shelf();

        $suggested = $this->getJson('/api/products/suggestions?q=steelseries')
            ->assertOk()
            ->json('data.categories');

        $this->assertSame('SteelSeries', $suggested[0]['name']);
        $this->assertSame('Accessories › Headphone', $suggested[0]['path']);
    }

    /**
     * The case that prompted this: four chips reading the same word, with
     * nothing to choose between them.
     */
    public function test_shelves_sharing_a_name_are_told_apart(): void
    {
        $this->shelf();

        $mouse = Category::create([
            'name' => 'Mouse Pad',
            'slug' => 'mouse-pad',
            'parent_id' => Category::where('slug', 'accessories')->value('id'),
            'is_active' => true,
        ]);
        Category::create([
            'name' => 'SteelSeries',
            'slug' => 'steelseries-mouse-pad',
            'parent_id' => $mouse->id,
            'is_active' => true,
        ]);

        $paths = collect(
            $this->getJson('/api/products/suggestions?q=steelseries')
                ->json('data.categories')
        )->pluck('path')->all();

        $this->assertEqualsCanonicalizing(
            ['Accessories › Headphone', 'Accessories › Mouse Pad'],
            $paths,
        );
    }

    /** A top-level shelf has no ancestry, and must not be given an empty one. */
    public function test_a_root_shelf_has_no_path(): void
    {
        Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);

        $this->assertSame(
            '',
            $this->getJson('/api/products/suggestions?q=laptop')
                ->json('data.categories.0.path'),
        );
    }

    // --- the product page's breadcrumb -------------------------------------

    /**
     * The trail has to be walkable, so the page needs the shelf and everything
     * above it — only the shelf itself was loaded, and a breadcrumb of one
     * level names what the shopper could already see.
     */
    public function test_a_product_carries_the_whole_trail(): void
    {
        $shelf = $this->shelf();
        $this->product($shelf);

        $product = app(ProductService::class)->getProductBySlug('arctis-nova-7');

        $this->assertSame('SteelSeries', $product->category->name);
        $this->assertSame('Headphone', $product->category->parent->name);
        $this->assertSame('Accessories', $product->category->parent->parent->name);
    }

    /** Loaded, not lazily fetched: the page is rendered from one payload. */
    public function test_the_trail_arrives_without_further_queries(): void
    {
        $shelf = $this->shelf();
        $this->product($shelf);

        $product = app(ProductService::class)->getProductBySlug('arctis-nova-7');

        $this->assertTrue($product->relationLoaded('category'));
        $this->assertTrue($product->category->relationLoaded('parent'));
        $this->assertTrue($product->category->parent->relationLoaded('parent'));
    }
}
