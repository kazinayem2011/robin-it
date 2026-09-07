<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The mega menu draws a brand's logo where the shop has uploaded one, and the
 * tree it draws from is cached for six hours.
 *
 * That cache was dropped on a Category or Product write and not on a Brand
 * one — yet every third-level entry in the tree is matched against `brands`
 * and carries that brand's logo, so a brand write changes the menu just as
 * surely. Uploading a logo therefore did nothing visible for up to six hours,
 * which is indistinguishable from the upload having failed.
 */
class MegaMenuBrandLogoTest extends TestCase
{
    use RefreshDatabase;

    /** A brand's logo is attached to the third-level entry of the same name. */
    private function menuLogoFor(string $name): ?string
    {
        foreach (app(CategoryService::class)->getMegaMenuTree() as $top) {
            foreach ($top['subcategories'] ?? [] as $sub) {
                foreach ($sub['children'] ?? [] as $child) {
                    if ($child['name'] === $name) {
                        return $child['logo'] ?? null;
                    }
                }
            }
        }

        return null;
    }

    private function seedTreeWithLeaf(string $leafName): void
    {
        $top = Category::create(['name' => 'Components', 'slug' => 'components', 'is_active' => true]);
        $sub = Category::create(['name' => 'Processor', 'slug' => 'processor', 'is_active' => true, 'parent_id' => $top->id]);
        $leaf = Category::create([
            'name' => $leafName,
            'slug' => Str::slug($leafName),
            'is_active' => true,
            'parent_id' => $sub->id,
        ]);

        // The tree only carries categories that hold something.
        Product::create([
            'category_id' => $leaf->id,
            'name' => $leafName.' Part',
            'slug' => Str::slug($leafName.' part'),
            'price' => 5000,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);
    }

    public function test_a_brand_logo_reaches_the_menu(): void
    {
        $this->seedTreeWithLeaf('AMD');
        Brand::create(['name' => 'AMD', 'slug' => 'amd', 'logo_path' => '/images/brands/amd.png']);

        $this->assertSame('/images/brands/amd.png', $this->menuLogoFor('AMD'));
    }

    /**
     * The regression this exists for: the menu is cached, so uploading a logo
     * has to drop that cache or nothing changes until it expires.
     */
    public function test_uploading_a_logo_updates_the_cached_menu_at_once(): void
    {
        $this->seedTreeWithLeaf('AMD');
        $brand = Brand::create(['name' => 'AMD', 'slug' => 'amd']);

        // Warm the cache while the brand has no logo, as a visitor would.
        $this->assertNull($this->menuLogoFor('AMD'));

        $brand->update(['logo_path' => '/images/brands/amd.png']);

        $this->assertSame(
            '/images/brands/amd.png',
            $this->menuLogoFor('AMD'),
            'the menu was still serving the tree it cached before the logo was uploaded'
        );
    }

    public function test_removing_a_logo_updates_the_cached_menu_too(): void
    {
        $this->seedTreeWithLeaf('AMD');
        $brand = Brand::create(['name' => 'AMD', 'slug' => 'amd', 'logo_path' => '/images/brands/amd.png']);

        $this->assertNotNull($this->menuLogoFor('AMD'));

        $brand->update(['logo_path' => null]);

        $this->assertNull($this->menuLogoFor('AMD'));
    }

    /** Deleting a brand has to take its logo out of the menu as well. */
    public function test_deleting_a_brand_updates_the_cached_menu(): void
    {
        $this->seedTreeWithLeaf('AMD');
        $brand = Brand::create(['name' => 'AMD', 'slug' => 'amd', 'logo_path' => '/images/brands/amd.png']);

        $this->assertNotNull($this->menuLogoFor('AMD'));

        $brand->delete();

        $this->assertNull($this->menuLogoFor('AMD'));
    }
}
