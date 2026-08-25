<?php

namespace Tests\Feature\Shop;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * A third of the main navigation led nowhere: Accessories, Server & Storage
 * and Offers & Deals all opened on "No products found in this category", and
 * the Offers button in the header was a 500 because the page it rendered had
 * never been written.
 */
class NavigationDeadEndsTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
    }

    private function tree(): Collection
    {
        return app(CategoryService::class)->getMegaMenuTree();
    }

    private function category(string $name, string $slug, array $extra = []): Category
    {
        return Category::create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ], $extra));
    }

    private function product(Category $category, bool $active = true): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'brand_id' => $this->brand->id,
            'name' => 'Test Part '.uniqid(),
            'slug' => 'test-part-'.uniqid(),
            'price' => 50000,
            'stock_quantity' => 3,
            'is_active' => $active,
        ]);
    }

    public function test_a_category_with_no_products_is_not_offered(): void
    {
        $this->category('Server & Storage', 'server-storage');

        $this->assertNotContains('server-storage', $this->tree()->pluck('slug'));
    }

    public function test_a_category_with_products_is_offered(): void
    {
        $this->product($this->category('Laptops', 'laptops'));

        $this->assertContains('laptops', $this->tree()->pluck('slug'));
    }

    /** Products sit on leaf categories, so a root counts through its descendants. */
    public function test_a_parent_counts_stock_held_by_a_grandchild(): void
    {
        $root = $this->category('Components', 'components');
        $child = $this->category('Storage', 'storage', ['parent_id' => $root->id]);
        $leaf = $this->category('NVMe', 'nvme', ['parent_id' => $child->id]);

        $this->product($leaf);

        $tree = $this->tree();
        $this->assertContains('components', $tree->pluck('slug'));

        $subs = collect($tree->firstWhere('slug', 'components')['subcategories']);
        $this->assertContains('storage', $subs->pluck('slug'));
    }

    public function test_an_empty_subcategory_is_pruned_from_a_stocked_parent(): void
    {
        $root = $this->category('Components', 'components');
        $stocked = $this->category('CPU', 'cpu', ['parent_id' => $root->id]);
        $this->category('Sound Cards', 'sound-cards', ['parent_id' => $root->id]);

        $this->product($stocked);

        $subs = collect($this->tree()->firstWhere('slug', 'components')['subcategories']);

        $this->assertContains('cpu', $subs->pluck('slug'));
        $this->assertNotContains('sound-cards', $subs->pluck('slug'));
    }

    /**
     * The discounts live on the products, so an offer category never holds any
     * of its own — it must not be pruned for that.
     */
    public function test_an_offer_category_survives_having_no_products(): void
    {
        $this->category('Offers & Deals', 'offers-deals', ['is_offer' => true]);

        $this->assertContains('offers-deals', $this->tree()->pluck('slug'));
    }

    public function test_an_inactive_product_does_not_keep_a_category_alive(): void
    {
        $this->product($this->category('Networking', 'networking'), active: false);

        $this->assertNotContains('networking', $this->tree()->pluck('slug'));
    }

    /** The header links here from every page; it used to be a 500. */
    public function test_the_offers_page_renders(): void
    {
        $response = $this->get('/offers');

        $response->assertStatus(200);

        $page = $response->viewData('page');

        $this->assertSame('Products/Index', $page['component']);
        $this->assertTrue($page['props']['onSaleOnly']);
    }

    /** Direct links to an unstocked category still work — they are just not advertised. */
    public function test_an_empty_category_page_still_loads(): void
    {
        $this->category('Server & Storage', 'server-storage');

        $this->get('/shop/server-storage')->assertStatus(200);
    }
}
