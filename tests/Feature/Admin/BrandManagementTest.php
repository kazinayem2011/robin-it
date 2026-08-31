<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Managing brands, which nothing could do.
 *
 * The twenty-eight brands existed because a seeder made them; there was no
 * controller, no screen and no route. `logo_path` was read in three places —
 * including the mega menu, which shows a logo where there is one — and written
 * by nothing, which is why every brand in that menu falls back to a lettermark.
 */
class BrandManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_the_brands_screen_renders(): void
    {
        Brand::create(['name' => 'ASUS', 'slug' => 'asus']);

        $this->actingAs($this->admin())
            ->get('/admin/brands')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Admin/Brands')
                    ->has('brands.data', 1)
                    ->has('counts')
            );
    }

    public function test_a_brand_can_be_created(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/brands', ['name' => 'Gigabyte'])
            ->assertCreated();

        $this->assertDatabaseHas('brands', ['name' => 'Gigabyte', 'slug' => 'gigabyte']);
    }

    /**
     * The mega menu matches a brand category to its logo by name, so two rows
     * called "ASUS" make that a coin toss.
     */
    public function test_two_brands_cannot_share_a_name(): void
    {
        Brand::create(['name' => 'ASUS', 'slug' => 'asus']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/brands', ['name' => 'ASUS'])
            ->assertStatus(422);

        $this->assertSame(1, Brand::count());
    }

    /** The gap this whole screen exists to close. */
    public function test_a_logo_can_be_saved(): void
    {
        $brand = Brand::create(['name' => 'MSI', 'slug' => 'msi']);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/brands/{$brand->id}", [
                'name' => 'MSI',
                'logo_path' => '/images/brands/msi.png',
            ])
            ->assertOk();

        $this->assertSame('/images/brands/msi.png', $brand->fresh()->logo_path);
    }

    /**
     * Losing a supplier is not losing the stock on the shelf: the products stay,
     * with no brand.
     */
    public function test_deleting_a_brand_keeps_its_products(): void
    {
        $brand = Brand::create(['name' => 'Corsair', 'slug' => 'corsair']);
        $category = Category::create(['name' => 'RAM', 'slug' => 'ram', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Vengeance 32GB',
            'slug' => 'vengeance-32gb',
            'price' => 12000,
            'stock_quantity' => 4,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/brands/{$brand->id}")
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertNull($product->fresh()->brand_id);
    }

    public function test_a_customer_cannot_manage_brands(): void
    {
        $brand = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->deleteJson("/api/admin/brands/{$brand->id}")
            ->assertForbidden();

        $this->assertSame(1, Brand::count());
    }

    // ------------------------------------------------- the category typeahead

    /**
     * The endpoint that replaced a 113 KB prop. Admin/Products shipped every
     * category to the browser to fill two dropdowns; this returns only what was
     * asked for.
     */
    public function test_categories_can_be_searched(): void
    {
        $parent = Category::create(['name' => 'Component', 'slug' => 'component', 'is_active' => true]);
        Category::create(['name' => 'Graphics Card', 'slug' => 'gfx', 'parent_id' => $parent->id, 'is_active' => true]);
        Category::create(['name' => 'Keyboard', 'slug' => 'keyboard', 'is_active' => true]);

        $data = $this->actingAs($this->admin())
            ->getJson('/api/admin/categories/search?q=graphics')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Graphics Card', $data[0]['name']);
        // The path, because names repeat across the tree.
        $this->assertSame('Component', $data[0]['path']);
    }

    public function test_the_search_is_capped(): void
    {
        for ($i = 0; $i < 60; $i++) {
            Category::create([
                'name' => "Cable {$i}",
                'slug' => "cable-{$i}",
                'is_active' => true,
            ]);
        }

        $data = $this->actingAs($this->admin())
            ->getJson('/api/admin/categories/search?q=cable')
            ->assertOk()
            ->json('data');

        // Feeds a typeahead: nobody scrolls to the fortieth suggestion.
        $this->assertLessThanOrEqual(40, count($data));
    }

    public function test_a_customer_cannot_search_categories(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->getJson('/api/admin/categories/search?q=x')
            ->assertForbidden();
    }
}
