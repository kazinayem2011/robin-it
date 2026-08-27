<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The browser must never be handed an image path that 404s.
 *
 * Every product's image was one: the seed data names thirty files under
 * public/images/products and that directory has never existed. Each was
 * requested, came back 404, drew as a broken image, and only then did the
 * front end's onError swap in the placeholder — so every product flashed
 * broken before it settled, and a shop page fired twenty failed requests.
 */
class ProductImageFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::create([
            'category_id' => Category::firstOrCreate(
                ['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]
            )->id,
            'name' => 'Test CPU',
            'slug' => 'test-cpu-image',
            'price' => 5000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function paths(): array
    {
        return [
            'a file that is not there' => [
                '/images/products/cpu-i9-14900ks.jpg',
                ProductImage::PLACEHOLDER,
            ],
            'a file that is' => [
                '/images/product-placeholder.svg',
                '/images/product-placeholder.svg',
            ],
            'nothing at all' => [null, ProductImage::PLACEHOLDER],
            'an empty string' => ['', ProductImage::PLACEHOLDER],
            'only whitespace' => ['   ', ProductImage::PLACEHOLDER],
            // Somebody else's server; we are in no position to check it.
            'a remote image' => [
                'https://cdn.example.com/a.jpg',
                'https://cdn.example.com/a.jpg',
            ],
            'a remote image over http' => [
                'http://cdn.example.com/a.jpg',
                'http://cdn.example.com/a.jpg',
            ],
        ];
    }

    #[DataProvider('paths')]
    public function test_a_path_resolves_to_something_that_loads(?string $stored, string $expected): void
    {
        $this->assertSame($expected, ProductImage::resolve($stored));
    }

    /**
     * The stored path is left exactly as entered.
     *
     * Resolving image_path itself would mean the admin's edit form reads back
     * a placeholder where a real path is stored, and saving would write that
     * placeholder over the original.
     */
    public function test_the_stored_path_is_not_rewritten(): void
    {
        $image = ProductImage::create([
            'product_id' => $this->product()->id,
            'image_path' => '/images/products/cpu-i9-14900ks.jpg',
            'is_primary' => true,
        ]);

        $this->assertSame('/images/products/cpu-i9-14900ks.jpg', $image->fresh()->image_path);
        $this->assertSame(ProductImage::PLACEHOLDER, $image->fresh()->image_url);

        $this->assertDatabaseHas('product_images', [
            'id' => $image->id,
            'image_path' => '/images/products/cpu-i9-14900ks.jpg',
        ]);
    }

    /** So the front end has it without asking for it. */
    public function test_the_resolved_url_is_serialised_alongside_the_path(): void
    {
        $image = ProductImage::create([
            'product_id' => $this->product()->id,
            'image_path' => '/images/products/nope.jpg',
            'is_primary' => true,
        ]);

        $array = $image->toArray();

        $this->assertArrayHasKey('image_url', $array);
        $this->assertSame(ProductImage::PLACEHOLDER, $array['image_url']);
        $this->assertSame('/images/products/nope.jpg', $array['image_path']);
    }

    /** The placeholder is shipped with the app, so this must always hold. */
    public function test_the_placeholder_itself_exists(): void
    {
        $this->assertFileExists(public_path(ltrim(ProductImage::PLACEHOLDER, '/')));
    }

    /** What the shop page sends, for every product on it. */
    public function test_the_shop_sends_no_path_that_would_404(): void
    {
        $product = $this->product();

        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => '/images/products/definitely-not-here.jpg',
            'is_primary' => true,
        ]);

        $payload = $this->getJson('/api/products')->assertSuccessful()->json();

        foreach (data_get($payload, 'data.data', data_get($payload, 'data', [])) as $row) {
            foreach ((array) data_get($row, 'images', []) as $image) {
                if (! isset($image['image_url'])) {
                    continue;
                }

                if (str_starts_with($image['image_url'], 'http')) {
                    continue;
                }

                $this->assertFileExists(
                    public_path(ltrim($image['image_url'], '/')),
                    "The shop offered {$image['image_url']}, which is not on disk."
                );
            }
        }
    }
}
