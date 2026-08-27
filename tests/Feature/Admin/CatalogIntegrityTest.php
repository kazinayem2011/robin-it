<?php

namespace Tests\Feature\Admin;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * products.category_id cascades on delete, so deleting a category silently wiped
 * every product beneath it, and grandchildren were orphaned to the root of the
 * mega menu. Deleting is now refused while products remain.
 */
class CatalogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function tree(): array
    {
        $root = Category::create(['name' => 'Components', 'slug' => 'components', 'is_active' => true]);
        $child = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'parent_id' => $root->id, 'is_active' => true]);
        $grand = Category::create(['name' => 'Intel', 'slug' => 'intel', 'parent_id' => $child->id, 'is_active' => true]);

        return [$root, $child, $grand];
    }

    private function productIn(Category $category, string $slug): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }

    public function test_deleting_a_category_holding_products_is_refused(): void
    {
        [$root, $child, $grand] = $this->tree();
        $this->productIn($grand, 'deep-product');

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/categories/{$root->id}")
            ->assertStatus(422);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('categories', 3);
    }

    public function test_the_refusal_explains_what_is_blocking_it(): void
    {
        [$root, $child] = $this->tree();
        $this->productIn($child, 'blocking-product');

        $response = $this->actingAs($this->admin())
            ->deleteJson("/api/admin/categories/{$root->id}");

        $response->assertStatus(422)
            ->assertJsonPath('error', true)
            ->assertJsonPath('data.product_count', 1);

        $this->assertStringContainsString('1 product', $response->json('message'));
    }

    public function test_an_empty_category_tree_can_still_be_deleted(): void
    {
        [$root] = $this->tree();

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/categories/{$root->id}")
            ->assertStatus(200);

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_deleting_never_leaves_an_orphaned_category_at_the_root(): void
    {
        [$root, $child, $grand] = $this->tree();

        $this->actingAs($this->admin())->deleteJson("/api/admin/categories/{$root->id}");

        // Previously "Intel" survived with parent_id = NULL and appeared as a
        // brand-new top-level entry in the mega menu.
        $this->assertDatabaseMissing('categories', ['slug' => 'intel']);
        $this->assertSame(0, Category::whereNull('parent_id')->count());
    }

    public function test_a_blog_post_can_be_created_without_the_publish_flag(): void
    {
        // `'is_published' => 'boolean'` leaves the key absent when not posted;
        // reading it unguarded threw an ErrorException and returned a 500.
        $this->actingAs($this->admin())->postJson('/api/admin/blogs', [
            'title' => 'Best GPUs of 2026',
            'category' => 'Guides',
            'excerpt' => 'A roundup of this year’s best graphics cards.',
            'content' => 'Long form content here.',
            'image_path' => '/images/gpu.jpg',
            'author_name' => 'Robin IT Team',
            'read_time' => '5 min',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $post = BlogPost::first();
        $this->assertNotNull($post);
        $this->assertFalse((bool) $post->is_published);
        $this->assertNull($post->published_at);
    }

    public function test_publishing_a_blog_post_stamps_published_at(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/blogs', [
            'title' => 'Live Article',
            'category' => 'News',
            'excerpt' => 'Short excerpt.',
            'content' => 'Body copy.',
            'image_path' => '/images/news.jpg',
            'author_name' => 'Robin IT Team',
            'read_time' => '2 min',
            'is_published' => true,
        ])->assertRedirect();

        $this->assertNotNull(BlogPost::first()->published_at);
    }

    public function test_generated_slugs_do_not_collide(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($admin)->postJson('/api/admin/products', [
                'category_id' => $category->id,
                'name' => 'Identical Product Name',
                'price' => 1000,
                'stock_quantity' => 1,
            ])->assertRedirect();
        }

        $slugs = Product::pluck('slug')->all();
        $this->assertCount(3, $slugs);
        $this->assertSame($slugs, array_unique($slugs), 'Slugs must be unique.');
    }

    public function test_saving_settings_invalidates_the_cache_customers_read(): void
    {
        $admin = $this->admin();

        SiteSetting::create(['key' => 'announcement_text', 'value' => 'Old announcement']);
        $this->assertSame('Old announcement', SiteSetting::getAllSettings()['announcement_text']);

        $this->actingAs($admin)->postJson('/api/admin/settings', [
            'settings' => ['announcement_text' => 'New announcement'],
        ])->assertStatus(200);

        // The save used to forget a different cache key, so the storefront kept
        // serving the old value for up to an hour.
        $this->assertSame('New announcement', SiteSetting::getAllSettings()['announcement_text']);
    }

    public function test_cancelling_an_order_from_the_admin_restores_stock(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);
        $product = $this->productIn($category, 'cancellable-product');

        $this->actingAs($customer)->postJson('/api/cart', [
            'product_id' => $product->id, 'quantity' => 2,
        ])->assertStatus(200);

        $this->actingAs($customer)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->assertSame(3, $product->fresh()->stock_quantity);

        $order = Order::first();
        $this->actingAs($admin)->patchJson("/api/admin/orders/{$order->id}/status", [
            'status' => 'cancelled',
        ])->assertStatus(200);

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }
}
