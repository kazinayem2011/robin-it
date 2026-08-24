<?php

namespace Tests\Feature\Performance;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Query budgets for the storefront.
 *
 * An N+1 does not fail anything — it just makes a page slower with every
 * product added, which is invisible on a seeded database and painful on a real
 * one. These assert the count does not grow with the size of the catalogue,
 * which is the property that actually matters.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalogue(int $products, int $categoriesDeep = 3): void
    {
        $brand = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);

        $root = Category::create(['name' => 'Components', 'slug' => 'components', 'is_active' => true]);

        foreach (['cpu' => 'Processor', 'motherboard' => 'Motherboard', 'ram' => 'Memory',
            'storage' => 'Storage', 'graphics-card' => 'Graphics', 'power-supply' => 'PSU',
            'pc-case' => 'Case', 'monitors' => 'Monitors'] as $slug => $name) {
            $slot = Category::create([
                'name' => $name, 'slug' => $slug,
                'parent_id' => $root->id, 'is_active' => true,
            ]);

            // Sub-categories, so the descendant walk has real depth.
            for ($d = 0; $d < $categoriesDeep; $d++) {
                Category::create([
                    'name' => "{$name} {$d}", 'slug' => "{$slug}-{$d}",
                    'parent_id' => $slot->id, 'is_active' => true,
                ]);
            }
        }

        $leaf = Category::where('slug', 'cpu-0')->first();

        for ($i = 0; $i < $products; $i++) {
            Product::create([
                'category_id' => $leaf->id, 'brand_id' => $brand->id,
                'name' => "Product {$i}", 'slug' => "product-{$i}",
                'price' => 1000 + $i, 'stock_quantity' => 5, 'is_active' => true,
            ]);
        }
    }

    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * The builder resolved descendants and counted stock inside the slot loop,
     * which meant 53 queries for 13 slots — each one walking the category tree
     * again.
     */
    public function test_the_pc_builder_does_not_query_per_slot(): void
    {
        $this->seedCatalogue(10);

        $queries = $this->countQueries(
            fn () => app(ProductService::class)->getPcBuilderCategories()
        );

        $this->assertLessThanOrEqual(
            5,
            $queries,
            "the builder ran {$queries} queries; it resolves every slot in a fixed few"
        );
    }

    /** The count must not grow with the number of slots that have products. */
    public function test_the_pc_builder_cost_is_flat_as_the_catalogue_grows(): void
    {
        $this->seedCatalogue(5);
        $small = $this->countQueries(
            fn () => app(ProductService::class)->getPcBuilderCategories()
        );

        // Ten times the products, same slots.
        $leaf = Category::where('slug', 'cpu-0')->first();
        for ($i = 100; $i < 150; $i++) {
            Product::create([
                'category_id' => $leaf->id, 'name' => "Extra {$i}",
                'slug' => "extra-{$i}", 'price' => 500, 'stock_quantity' => 1,
                'is_active' => true,
            ]);
        }

        $large = $this->countQueries(
            fn () => app(ProductService::class)->getPcBuilderCategories()
        );

        $this->assertLessThanOrEqual($small, $large, 'the query count grew with the catalogue');
    }

    public function test_the_product_listing_does_not_query_per_product(): void
    {
        $this->seedCatalogue(5);
        $few = $this->countQueries(fn () => $this->getJson('/api/products?per_page=50'));

        $leaf = Category::where('slug', 'cpu-0')->first();
        for ($i = 200; $i < 240; $i++) {
            Product::create([
                'category_id' => $leaf->id, 'name' => "More {$i}",
                'slug' => "more-{$i}", 'price' => 500, 'stock_quantity' => 1,
                'is_active' => true,
            ]);
        }

        $many = $this->countQueries(fn () => $this->getJson('/api/products?per_page=50'));

        $this->assertLessThanOrEqual(
            $few,
            $many,
            "listing 45 products cost {$many} queries against {$few} for 5 — that is an N+1"
        );
    }

    /**
     * The admin product list once ran 61 queries for 20 rows: a compatibility
     * check added later read each product's specs and category individually.
     * The budgets above only covered the storefront, which is why it got in.
     */
    public function test_the_admin_product_list_does_not_query_per_product(): void
    {
        $this->seedCatalogue(5);
        $admin = User::factory()->create(['role' => 'admin']);

        $few = $this->countQueries(
            fn () => $this->actingAs($admin)->get('/admin/products')
        );

        $leaf = Category::where('slug', 'cpu-0')->first();
        for ($i = 400; $i < 419; $i++) {
            Product::create([
                'category_id' => $leaf->id, 'name' => "Row {$i}",
                'slug' => "row-{$i}", 'price' => 500, 'stock_quantity' => 1,
                'is_active' => true,
            ]);
        }

        $many = $this->countQueries(
            fn () => $this->actingAs($admin)->get('/admin/products')
        );

        // Not assertSame: Laravel skips an eager load whose parent set has no
        // matching rows, so the count can legitimately dip by one as the
        // catalogue fills out. The property being asserted is that it never
        // *grows* with the number of rows.
        $this->assertLessThanOrEqual(
            $few,
            $many,
            "listing 24 products cost {$many} queries against {$few} for 5 — that is an N+1"
        );
    }

    public function test_the_admin_stock_screen_does_not_query_per_product(): void
    {
        $this->seedCatalogue(5);
        $admin = User::factory()->create(['role' => 'admin']);

        $few = $this->countQueries(fn () => $this->actingAs($admin)->get('/admin/stock'));

        $leaf = Category::where('slug', 'cpu-0')->first();
        for ($i = 500; $i < 519; $i++) {
            Product::create([
                'category_id' => $leaf->id, 'name' => "Stocked {$i}",
                'slug' => "stocked-{$i}", 'price' => 500, 'stock_quantity' => 1,
                'is_active' => true,
            ]);
        }

        $this->assertLessThanOrEqual(
            $few,
            $this->countQueries(fn () => $this->actingAs($admin)->get('/admin/stock'))
        );
    }

    public function test_the_filter_facets_cost_is_flat(): void
    {
        $this->seedCatalogue(5);
        $few = $this->countQueries(fn () => $this->getJson('/api/products/filters'));

        $leaf = Category::where('slug', 'cpu-0')->first();
        for ($i = 300; $i < 340; $i++) {
            Product::create([
                'category_id' => $leaf->id, 'name' => "Yet more {$i}",
                'slug' => "yet-more-{$i}", 'price' => 500, 'stock_quantity' => 1,
                'is_active' => true,
            ]);
        }

        $this->assertLessThanOrEqual(
            $few,
            $this->countQueries(fn () => $this->getJson('/api/products/filters'))
        );
    }
}
