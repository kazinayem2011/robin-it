<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The mega menu is rebuilt for the header, which renders on every page — a read
 * of the whole category table, a distinct scan of products and a tree walk, per
 * visitor per navigation. It is cached now, so what matters is that an admin
 * editing the catalogue sees the change rather than the old navigation.
 */
class CatalogueCacheTest extends TestCase
{
    use RefreshDatabase;

    private function stockedCategory(string $name, string $slug): Category
    {
        $category = Category::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);

        Product::create([
            'category_id' => $category->id,
            'name' => $name.' Product',
            'slug' => $slug.'-product',
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        return $category;
    }

    public function test_the_mega_menu_is_served_from_cache_on_the_second_call(): void
    {
        $this->stockedCategory('Processors', 'cpu');

        $this->getJson('/api/categories/mega-menu')->assertStatus(200);

        DB::enableQueryLog();
        $this->getJson('/api/categories/mega-menu')->assertStatus(200);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'The cached mega menu still queried the database.');
    }

    public function test_a_new_category_appears_without_waiting_for_the_cache_to_expire(): void
    {
        $this->stockedCategory('Processors', 'cpu');

        $this->getJson('/api/categories/mega-menu')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->stockedCategory('Graphics Cards', 'gpu');

        $this->getJson('/api/categories/mega-menu')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_a_deleted_category_leaves_the_navigation_at_once(): void
    {
        $cpu = $this->stockedCategory('Processors', 'cpu');
        $this->stockedCategory('Graphics Cards', 'gpu');

        $this->getJson('/api/categories/mega-menu')->assertJsonCount(2, 'data');

        $cpu->products()->delete();
        $cpu->delete();

        $this->getJson('/api/categories/mega-menu')->assertJsonCount(1, 'data');
    }

    /** The admin screens write through the same models, so the same hook fires. */
    public function test_creating_a_product_from_the_admin_refreshes_the_navigation(): void
    {
        $empty = Category::create(['name' => 'Monitors', 'slug' => 'monitors', 'is_active' => true]);

        // A category with nothing in it is deliberately left out of the menu.
        $this->getJson('/api/categories/mega-menu')->assertJsonCount(0, 'data');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/products', [
                'category_id' => $empty->id,
                'name' => 'Dell UltraSharp',
                'price' => 45000,
                'stock_quantity' => 3,
            ])->assertStatus(201);

        $this->getJson('/api/categories/mega-menu')->assertJsonCount(1, 'data');
    }

    /**
     * The constraint the test suite cannot see on its own.
     *
     * Tests run on the `array` cache store, which keeps live PHP references and
     * never serialises. Production runs on `database`, and config/cache.php
     * sets `serializable_classes => false` — Laravel's secure default, meaning
     * nothing read back out of the cache may reconstruct a PHP class.
     *
     * So caching a Collection passed every test and then threw on the *second*
     * request in a browser, because only a cache hit deserialises. These assert
     * the payload against the production rule directly.
     */
    #[DataProvider('cachedCatalogueKeyProvider')]
    public function test_the_cached_payload_holds_no_objects(string $key): void
    {
        $this->stockedCategory('Processors', 'cpu');

        $this->getJson('/api/categories/mega-menu')->assertStatus(200);
        $this->getJson('/api/categories/featured')->assertStatus(200);

        $cached = Cache::get($key);
        $this->assertNotNull($cached, "{$key} was never cached.");

        $restored = unserialize(serialize($cached), ['allowed_classes' => false]);

        $this->assertSame(
            $cached,
            $restored,
            "{$key} does not survive the cache round trip that production performs."
        );
    }

    /** @return array<string, array{0: string}> */
    public static function cachedCatalogueKeyProvider(): array
    {
        return [
            'mega menu' => [CategoryService::MEGA_MENU_KEY],
            'featured categories' => [CategoryService::FEATURED_KEY],
        ];
    }

    /** A second request must be served the same thing the first one built. */
    public function test_a_cache_hit_returns_the_same_payload_as_the_miss(): void
    {
        $this->stockedCategory('Processors', 'cpu');

        $miss = $this->getJson('/api/categories/mega-menu')->assertStatus(200)->json('data');
        $hit = $this->getJson('/api/categories/mega-menu')->assertStatus(200)->json('data');

        $this->assertSame($miss, $hit);
    }

    public function test_featured_categories_are_cached_and_invalidated_together(): void
    {
        $this->stockedCategory('Processors', 'cpu');

        $this->getJson('/api/categories/featured')->assertStatus(200);
        $this->assertNotNull(Cache::get(CategoryService::FEATURED_KEY));

        CategoryService::flush();

        $this->assertNull(Cache::get(CategoryService::FEATURED_KEY));
        $this->assertNull(Cache::get(CategoryService::MEGA_MENU_KEY));
    }
}
