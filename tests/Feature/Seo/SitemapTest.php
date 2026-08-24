<?php

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What crawlers are told about the site.
 *
 * The two failure modes that matter: pointing them at pages that should never
 * be indexed, and not pointing them at the products at all.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $slug, bool $active = true): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'GPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id, 'name' => 'Card '.$slug,
            'slug' => $slug, 'price' => 1000, 'stock_quantity' => 1,
            'is_active' => $active,
        ]);
    }

    public function test_the_sitemap_is_valid_xml(): void
    {
        $this->product('rtx-4090');

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'the sitemap is not parseable XML');
    }

    public function test_products_are_listed(): void
    {
        $this->product('rtx-4090');

        $this->get('/sitemap.xml')->assertSee('/products/rtx-4090');
    }

    /** A delisted product in the sitemap sends crawlers to a dead page. */
    public function test_an_inactive_product_is_not_listed(): void
    {
        $this->product('discontinued-card', active: false);

        $this->get('/sitemap.xml')->assertDontSee('/products/discontinued-card');
    }

    public function test_categories_and_the_shop_are_listed(): void
    {
        Category::create(['slug' => 'graphics-cards', 'name' => 'Graphics Cards', 'is_active' => true]);

        $response = $this->get('/sitemap.xml');

        $response->assertSee('/shop/graphics-cards');
        $response->assertSee('/products');
    }

    /** The whole point of the disallow list. */
    public function test_nothing_private_is_advertised_to_crawlers(): void
    {
        $this->product('rtx-4090');

        $content = $this->get('/sitemap.xml')->getContent();

        foreach (['/admin', '/account', '/checkout', '/cart', '/dashboard'] as $private) {
            $this->assertStringNotContainsString(
                '<loc>'.rtrim(config('app.url'), '/').$private,
                $content,
                "{$private} was advertised in the sitemap"
            );
        }
    }

    public function test_robots_blocks_the_areas_behind_a_login(): void
    {
        $content = $this->get('/robots.txt')
            ->assertStatus(200)
            ->getContent();

        foreach (['/admin', '/account', '/checkout', '/cart', '/orders'] as $private) {
            $this->assertStringContainsString("Disallow: {$private}", $content);
        }
    }

    public function test_robots_points_at_the_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertSee('Sitemap: '.rtrim(config('app.url'), '/').'/sitemap.xml');
    }

    public function test_the_footer_reports_the_real_number_of_showrooms(): void
    {
        Store::create([
            'name' => 'One', 'address' => 'a', 'phone' => '1', 'is_active' => true,
        ]);
        Store::create([
            'name' => 'Two', 'address' => 'b', 'phone' => '2', 'is_active' => true,
        ]);
        Store::create([
            'name' => 'Closed', 'address' => 'c', 'phone' => '3', 'is_active' => false,
        ]);

        $props = $this->get('/')->viewData('page')['props'];

        // The footer claimed "15+" for years against whatever actually existed.
        $this->assertSame(2, $props['showroom_count']);
    }
}
