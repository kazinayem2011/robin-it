<?php

namespace Tests\Feature\Security;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rich text is cleaned by the model, not by whoever remembered to.
 *
 * Every column here is rendered on a public page with
 * dangerouslySetInnerHTML. Three of them were purified inside the admin
 * controller that happened to write them, which held only for as long as that
 * stayed the one way in — an import, a console command, a second endpoint or a
 * seeder would have stored exactly what it was handed.
 *
 * These tests write through the model directly, with no request and no
 * controller anywhere in the path. If the rule ever moves back out to a call
 * site, they fail.
 */
class RichContentAtTheModelTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTILE = '<p>Real copy.</p><script>alert(document.domain)</script>'
        .'<img src=x onerror="alert(1)"><a href="javascript:alert(1)">click</a>';

    private function category(): Category
    {
        return Category::create(['name' => 'Storage', 'slug' => 'storage', 'is_active' => true]);
    }

    public static function productColumns(): array
    {
        return [
            'description' => ['description'],
            'key features' => ['key_features'],
        ];
    }

    #[DataProvider('productColumns')]
    public function test_a_product_written_without_a_controller_is_still_cleaned(string $column): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'name' => 'Test SSD',
            'slug' => 'test-ssd',
            'price' => 5000,
            'stock_quantity' => 0,
            'is_active' => true,
            $column => self::HOSTILE,
        ]);

        $this->assertClean($product->fresh()->{$column});
    }

    #[DataProvider('productColumns')]
    public function test_updating_a_product_outside_the_admin_is_cleaned_too(string $column): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'name' => 'Test SSD',
            'slug' => 'test-ssd',
            'price' => 5000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $product->update([$column => self::HOSTILE]);

        $this->assertClean($product->fresh()->{$column});
    }

    public function test_an_article_written_without_a_controller_is_still_cleaned(): void
    {
        $post = BlogPost::create([
            'title' => 'Buying guide',
            'slug' => 'buying-guide',
            'image_path' => 'images/blog/placeholder.jpg',
            'content' => self::HOSTILE,
            'is_published' => true,
        ]);

        $this->assertClean($post->fresh()->content);
    }

    public function test_a_content_page_written_without_a_controller_is_still_cleaned(): void
    {
        $page = ContentPage::create([
            'title' => 'About us',
            'slug' => 'about-us',
            'body' => self::HOSTILE,
        ]);

        $this->assertClean($page->fresh()->body);
    }

    /** Null stays null: a nullable column must not become an empty string. */
    public function test_nothing_is_invented_for_an_empty_field(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'name' => 'Plain',
            'slug' => 'plain',
            'price' => 100,
            'stock_quantity' => 0,
            'is_active' => true,
            'description' => null,
        ]);

        $this->assertNull($product->fresh()->description);
    }

    /** The copy survives; only the weapons go. */
    private function assertClean(?string $stored): void
    {
        $this->assertNotNull($stored);
        $this->assertStringContainsString('Real copy.', $stored, 'The legitimate markup was thrown away.');
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }
}
