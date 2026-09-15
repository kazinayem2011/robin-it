<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a crawler reads, before a line of JavaScript has run.
 *
 * The shop is Inertia, so every meta tag used to be written by React once the
 * bundle had loaded. Google reaches them eventually, on a second pass;
 * Facebook, WhatsApp, LinkedIn and Twitter never do — they read the HTML as
 * delivered and stop. So every link anyone shared, of any product, arrived
 * with no title, no description and no picture, and all 1,271 products
 * answered a search engine with the same four words: the shop's name.
 *
 * These assert the delivered HTML, which is the only thing those readers see.
 */
class SeoTagsTest extends TestCase
{
    use RefreshDatabase;

    /* The shop has one factory, for users, so these are built by hand. */
    private function product(array $attributes = []): Product
    {
        $root = Category::create([
            'name' => 'Desktop',
            'slug' => 'desktop-'.Str::random(6),
            'is_active' => true,
        ]);

        $name = $attributes['name'] ?? 'A Product';

        return Product::create($attributes + [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'category_id' => $root->id,
            'price' => 1000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }

    public function test_a_product_page_carries_its_own_title_and_description(): void
    {
        $product = $this->product([
            'name' => 'Keychron K8 Pro Mechanical Keyboard',
            'short_description' => 'A hot-swappable board with QMK and a metal case.',
        ]);

        $response = $this->get("/products/{$product->slug}");

        $response->assertOk();
        $response->assertSee('Keychron K8 Pro Mechanical Keyboard', false);
        $response->assertSee('A hot-swappable board with QMK and a metal case.', false);
        $response->assertSee('property="og:type" content="product"', false);
    }

    /** The shop's name goes after the page's own, never instead of it. */
    public function test_the_title_names_the_page_before_the_shop(): void
    {
        $product = $this->product(['name' => 'Logitech MX Master 3S']);

        $this->get("/products/{$product->slug}")
            ->assertSee('<title inertia>Logitech MX Master 3S | ', false);
    }

    /**
     * A share card is fetched by a machine that has no idea what site the path
     * came from, so a relative og:image is simply dropped.
     */
    public function test_the_share_image_is_an_absolute_url(): void
    {
        $product = $this->product(['name' => 'ASUS ROG Strix']);
        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => '/storage/uploads/products/rog.jpg',
        ]);

        $this->get("/products/{$product->slug}")
            ->assertSee('property="og:image" content="'.url('/storage/uploads/products/rog.jpg').'"', false);
    }

    /**
     * The picture the share card falls back to has to be a file that exists.
     *
     * It pointed at /images/hero_gaming_pc.png, which has never been in this
     * repository — so the default og:image was a 404 and every card without a
     * picture of its own arrived blank, which is the whole of what was being
     * reported from WhatsApp.
     */
    public function test_the_fallback_share_image_is_a_file_that_exists(): void
    {
        $image = Seo::for()['image'];

        $this->assertStringStartsWith(url('/'), $image);
        $this->assertFileExists(
            public_path(ltrim((string) parse_url($image, PHP_URL_PATH), '/')),
        );
    }

    /**
     * An SVG is not a share picture.
     *
     * Facebook, WhatsApp and LinkedIn draw a fixed set of raster formats and
     * show nothing at all for anything else. This matters far more than it
     * sounds: 1,265 of the shop's 1,267 products carry the SVG placeholder as
     * their only image, so offering it meant every one of those links arrived
     * with a blank where the picture goes.
     */
    public function test_a_placeholder_svg_is_not_offered_as_the_share_picture(): void
    {
        $product = $this->product(['name' => 'Sample AI PC']);
        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => '/images/product-placeholder.svg',
        ]);

        $response = $this->get("/products/{$product->slug}");

        $response->assertDontSee('og:image" content="'.url('/images/product-placeholder.svg'), false);
        $response->assertSee('property="og:image" content="'.url('/images/og-default.jpg').'"', false);
    }

    /** Google's Product markup rejects one just as surely, and says nothing. */
    public function test_the_schema_does_not_publish_an_svg_either(): void
    {
        $product = $this->product(['name' => 'Sample AI PC']);
        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => '/images/product-placeholder.svg',
        ]);

        $schema = Seo::productSchema($product->fresh()->load('images'));

        $this->assertStringEndsWith('.jpg', $schema['image']);
    }

    /**
     * The size, on a page that named itself.
     *
     * A controller settles these tags and hands them to the page as a prop;
     * the layout then calls the same method again on whatever the page gave
     * it, because most pages give nothing. Running a settled array back
     * through was harmless in every field but the picture — by then it is an
     * absolute URL rather than a path, so there was no file left to measure,
     * and every page with a title of its own quietly lost its dimensions.
     */
    public function test_the_image_keeps_its_dimensions_on_a_page_that_names_itself(): void
    {
        $this->get('/shop')
            ->assertSee('property="og:image:width" content="1200"', false)
            ->assertSee('property="og:image:height" content="630"', false);
    }

    /** Written by hand for years; now each of these says what it is. */
    public function test_the_pages_that_had_no_tags_of_their_own_now_have_them(): void
    {
        foreach ([
            '/shop' => 'Shop All Products',
            '/about' => 'About Us',
            '/contact' => 'Contact Us',
            '/stores' => 'Showrooms',
            '/warranty' => 'Warranty',
            '/pc-builder' => 'PC Builder',
        ] as $path => $expected) {
            $this->get($path)->assertSee("<title inertia>{$expected} | ", false);
        }
    }

    public function test_a_category_page_is_named_after_its_shelf(): void
    {
        $root = Category::create([
            'name' => 'Mechanical Keyboard',
            'slug' => 'mechanical-keyboard',
            'is_active' => true,
        ]);

        $this->get("/shop/{$root->slug}")
            ->assertSee('<title inertia>Mechanical Keyboard | ', false);
    }

    /**
     * Without the query string. `?page=2` and `?sort=price` are one page as far
     * as indexing goes, and pointing each at itself is the duplicate-content
     * problem canonical exists to solve.
     */
    public function test_the_canonical_drops_the_query_string(): void
    {
        $product = $this->product(['name' => 'Dell UltraSharp']);

        $this->get("/products/{$product->slug}?ref=facebook&utm_source=x")
            ->assertSee('rel="canonical" href="'.url("/products/{$product->slug}").'"', false);
    }

    /** One person's page. A search result pointing at it helps nobody. */
    public function test_a_cart_is_kept_out_of_the_index(): void
    {
        $this->get('/cart')->assertSee('name="robots" content="noindex, follow"', false);
    }

    public function test_a_shop_page_is_not_kept_out_of_the_index(): void
    {
        $this->get('/shop')->assertDontSee('name="robots"', false);
    }

    /**
     * The Product markup. This is what puts a price and a stock state in a
     * search result rather than a bare blue link.
     */
    public function test_a_product_page_carries_its_schema(): void
    {
        $product = $this->product(['name' => 'Corsair K70', 'price' => 12500]);

        $response = $this->get("/products/{$product->slug}");

        $response->assertSee('application/ld+json', false);
        $response->assertSee('"@type":"Product"', false);
        $response->assertSee('"priceCurrency":"BDT"', false);
        $response->assertSee('"name":"Corsair K70"', false);
    }

    public function test_the_schema_says_out_of_stock_when_it_is(): void
    {
        $product = $this->product(['name' => 'Sold Out Thing', 'stock_quantity' => 0]);

        $this->get("/products/{$product->slug}")
            ->assertSee('schema.org/OutOfStock', false);
    }

    public function test_the_schema_says_in_stock_when_it_is(): void
    {
        $product = $this->product(['name' => 'Available Thing', 'stock_quantity' => 9]);

        $this->get("/products/{$product->slug}")
            ->assertSee('schema.org/InStock', false);
    }

    /**
     * The one piece of markup that gets a shop's results suppressed rather
     * than merely ignored. The page falls back to five stars when a product
     * has no reviews, and that must never reach here.
     */
    public function test_no_rating_is_published_for_a_product_with_no_reviews(): void
    {
        $product = $this->product(['name' => 'Unreviewed Thing']);

        $this->get("/products/{$product->slug}")
            ->assertDontSee('aggregateRating', false);
    }

    /** A page that sets nothing still answers with the shop's own details. */
    public function test_a_page_with_no_details_of_its_own_still_has_tags(): void
    {
        $response = $this->get('/');

        $response->assertSee('<meta inertia name="description" content="', false);
        $response->assertSee('property="og:site_name"', false);
        $response->assertSee('name="twitter:card" content="summary_large_image"', false);
    }
}
