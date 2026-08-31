<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product listed under more than one category.
 *
 * One `category_id` was enough while the tree described what a thing is. It
 * stopped being enough once the tree also described who makes it: an Asus
 * gaming laptop belongs under both "Gaming Laptop > Asus" and "All Laptop >
 * Asus", and a single column can only put it in one, so it disappears from the
 * other.
 *
 * The pivot and the storefront reads were built and verified by hand; this is
 * the part that was never pinned — the admin round trip, and the rule that a
 * product cannot be taken out of its own breadcrumb.
 */
class MultiCategoryProductTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name, string $slug): Category
    {
        return Category::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
    }

    private function product(Category $primary): Product
    {
        return Product::create([
            'category_id' => $primary->id,
            'name' => 'Asus TUF A15',
            'slug' => 'asus-tuf-a15',
            'price' => 140000,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_a_product_can_be_listed_under_several_categories(): void
    {
        $gaming = $this->category('Gaming Laptop', 'gaming-laptop');
        $all = $this->category('All Laptop', 'all-laptop');
        $product = $this->product($gaming);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'category_ids' => [$all->id],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$gaming->id, $all->id],
            $product->categories()->pluck('categories.id')->all(),
        );
    }

    /**
     * The primary is what gives a product its breadcrumb and canonical URL, so
     * omitting it from the list must not remove it. A caller sending only the
     * extras is the normal case, not an instruction to unfile the product.
     */
    public function test_the_primary_category_survives_being_left_out(): void
    {
        $gaming = $this->category('Gaming Laptop', 'gaming-laptop');
        $all = $this->category('All Laptop', 'all-laptop');
        $product = $this->product($gaming);

        $product->syncCategories([$all->id]);

        $this->assertContains(
            $gaming->id,
            $product->categories()->pluck('categories.id')->all(),
        );
    }

    public function test_clearing_the_extras_leaves_only_the_primary(): void
    {
        $gaming = $this->category('Gaming Laptop', 'gaming-laptop');
        $all = $this->category('All Laptop', 'all-laptop');
        $product = $this->product($gaming);
        $product->syncCategories([$all->id]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['category_ids' => []])
            ->assertOk();

        $this->assertSame(
            [$gaming->id],
            $product->categories()->pluck('categories.id')->all(),
        );
    }

    /**
     * Absent is not empty. Editing a price must not quietly unfile a product
     * from every category somebody chose for it.
     */
    public function test_omitting_the_field_changes_nothing(): void
    {
        $gaming = $this->category('Gaming Laptop', 'gaming-laptop');
        $all = $this->category('All Laptop', 'all-laptop');
        $product = $this->product($gaming);
        $product->syncCategories([$all->id]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['price' => 135000])
            ->assertOk();

        $this->assertCount(2, $product->categories()->get());
    }

    /** The point of the whole thing: it shows up under both. */
    public function test_the_catalogue_finds_it_under_either_category(): void
    {
        $gaming = $this->category('Gaming Laptop', 'gaming-laptop');
        $all = $this->category('All Laptop', 'all-laptop');
        $product = $this->product($gaming);
        $product->syncCategories([$all->id]);

        $service = app(ProductService::class);

        foreach (['gaming-laptop', 'all-laptop'] as $slug) {
            $ids = $service
                ->getFilteredProducts(['category_slug' => $slug])
                ->pluck('id')
                ->all();

            $this->assertContains($product->id, $ids, "Missing under {$slug}.");
        }
    }

    /**
     * whereHas rather than a join, so a product matching two of the categories
     * being filtered on comes back once — otherwise it is a duplicate card in
     * the grid and a doubled figure in every count built on the same query.
     */
    public function test_it_appears_once_even_when_it_matches_twice(): void
    {
        $parent = $this->category('Laptop', 'laptop');
        $gaming = $this->category('Gaming Laptop', 'laptop-gaming');
        $all = $this->category('All Laptop', 'laptop-all');
        $gaming->update(['parent_id' => $parent->id]);
        $all->update(['parent_id' => $parent->id]);

        $product = $this->product($gaming);
        $product->syncCategories([$all->id]);

        $ids = app(ProductService::class)
            ->getFilteredProducts(['category_slug' => 'laptop'])
            ->pluck('id')
            ->all();

        $this->assertSame([$product->id], $ids);
    }

    /**
     * Anything that writes a product without going through the admin — a
     * seeder, an import, tinker — must still be findable, because the catalogue
     * reads the pivot and only a model event puts the primary there.
     */
    public function test_a_product_created_outside_the_admin_is_still_listed(): void
    {
        $category = $this->category('Keyboard', 'keyboard');
        $product = $this->product($category);

        $this->assertSame(
            [$category->id],
            $product->categories()->pluck('categories.id')->all(),
        );
    }
}
