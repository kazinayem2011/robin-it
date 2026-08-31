<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * More than one photo for a product, and for each of its options.
 *
 * The admin form carried a single `image_path` string, so a product had exactly
 * one photo — no back of the box, no ports, no what-is-in-the-carton — and an
 * option had one shot in a single column on its own row. `product_images` could
 * always hold more; nothing ever wrote a second row.
 */
class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'MSI Cyborg 15',
            'slug' => 'msi-cyborg-15',
            'price' => 132000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    private function paths(Product $product): array
    {
        return $product->images()->pluck('image_path')->all();
    }

    public function test_a_product_can_have_several_photos(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'images' => [
                    ['image_path' => '/img/front.jpg'],
                    ['image_path' => '/img/back.jpg'],
                    ['image_path' => '/img/ports.jpg'],
                ],
            ])
            ->assertOk();

        $this->assertSame(
            ['/img/front.jpg', '/img/back.jpg', '/img/ports.jpg'],
            $this->paths($product),
        );
    }

    /**
     * Exactly one photo leads. None means the page shows a placeholder with
     * real photos sitting behind it; two means the lead is whichever the
     * database returns first, which can change between requests.
     */
    public function test_exactly_one_photo_leads(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'images' => [
                    ['image_path' => '/img/a.jpg'],
                    ['image_path' => '/img/b.jpg', 'is_primary' => true],
                    ['image_path' => '/img/c.jpg', 'is_primary' => true],
                ],
            ])
            ->assertOk();

        $primary = $product->images()->where('is_primary', true)->get();

        $this->assertCount(1, $primary);
        // The first one flagged wins, and it moves to the front.
        $this->assertSame('/img/b.jpg', $primary->first()->image_path);
        $this->assertSame('/img/b.jpg', $this->paths($product)[0]);
    }

    /** Order is what was given, not insertion order. */
    public function test_reordering_sticks(): void
    {
        $product = $this->product();
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}", [
            'images' => [
                ['image_path' => '/img/a.jpg'],
                ['image_path' => '/img/b.jpg'],
                ['image_path' => '/img/c.jpg'],
            ],
        ])->assertOk();

        $ids = $product->images()->pluck('id', 'image_path');

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}", [
            'images' => [
                ['id' => $ids['/img/c.jpg'], 'image_path' => '/img/c.jpg'],
                ['id' => $ids['/img/a.jpg'], 'image_path' => '/img/a.jpg'],
                ['id' => $ids['/img/b.jpg'], 'image_path' => '/img/b.jpg'],
            ],
        ])->assertOk();

        $this->assertSame(['/img/c.jpg', '/img/a.jpg', '/img/b.jpg'], $this->paths($product));

        // Rows are updated, not deleted and recreated, so ids survive a reorder.
        $this->assertSame(
            $ids->sort()->values()->all(),
            $product->images()->pluck('id')->sort()->values()->all(),
        );
    }

    /** Editing a price must not throw away the photos. */
    public function test_omitting_the_gallery_leaves_it_alone(): void
    {
        $product = $this->product();
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}", [
            'images' => [['image_path' => '/img/a.jpg'], ['image_path' => '/img/b.jpg']],
        ])->assertOk();

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}", [
            'price' => 129000,
        ])->assertOk();

        $this->assertCount(2, $product->images()->get());
    }

    public function test_an_empty_gallery_removes_every_photo(): void
    {
        $product = $this->product();
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}", [
            'images' => [['image_path' => '/img/a.jpg']],
        ])->assertOk();

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}", [
            'images' => [],
        ])->assertOk();

        $this->assertSame([], $this->paths($product));
    }

    /** The same file twice is a repeated thumbnail and two identical slides. */
    public function test_a_duplicate_photo_is_dropped(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'images' => [
                    ['image_path' => '/img/a.jpg'],
                    ['image_path' => '/img/a.jpg'],
                    ['image_path' => '/img/b.jpg'],
                ],
            ])
            ->assertOk();

        $this->assertSame(['/img/a.jpg', '/img/b.jpg'], $this->paths($product));
    }

    /**
     * The single field was the whole of a product's photography before this.
     * Anything still posting it — an older client, an import — must keep
     * working rather than silently saving a product with no picture.
     */
    public function test_the_old_single_image_field_still_works(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'image_path' => '/img/only.jpg',
            ])
            ->assertOk();

        $this->assertSame(['/img/only.jpg'], $this->paths($product));
        $this->assertTrue($product->images()->first()->is_primary);
    }

    public function test_a_product_is_created_with_its_whole_gallery(): void
    {
        $category = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', [
                'name' => 'MSI Katana 15',
                'category_id' => $category->id,
                'price' => 150000,
                'images' => [
                    ['image_path' => '/img/1.jpg', 'alt_text' => 'Front'],
                    ['image_path' => '/img/2.jpg'],
                ],
            ])
            ->assertCreated();

        $product = Product::firstWhere('name', 'MSI Katana 15');

        $this->assertSame(['/img/1.jpg', '/img/2.jpg'], $this->paths($product));
        $this->assertSame('Front', $product->images()->first()->alt_text);
    }

    public function test_more_than_twelve_photos_is_refused(): void
    {
        $product = $this->product();

        $images = [];

        for ($i = 0; $i < 13; $i++) {
            $images[] = ['image_path' => "/img/{$i}.jpg"];
        }

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['images' => $images])
            ->assertStatus(422);
    }

    /** An option's photos are its own, and never appear in the product's gallery. */
    public function test_option_photos_are_kept_apart_from_the_products(): void
    {
        $product = $this->product();

        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => '/img/product.jpg',
            'is_primary' => true,
            'position' => 0,
        ]);

        $variant = $product->variants()->create([
            'name' => 'White',
            'options' => ['Colour' => 'White'],
            'sku' => 'W-1',
            'is_active' => true,
            'position' => 0,
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'image_path' => '/img/white.jpg',
            'is_primary' => true,
            'position' => 0,
        ]);

        $this->assertSame(['/img/product.jpg'], $this->paths($product));
        $this->assertSame(['/img/white.jpg'], $variant->images()->pluck('image_path')->all());
        $this->assertCount(2, $product->allImages()->get());
    }

    /** Deleting an option takes its photos, not the product's. */
    public function test_removing_an_option_removes_only_its_photos(): void
    {
        $product = $this->product();

        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => '/img/product.jpg',
            'is_primary' => true,
            'position' => 0,
        ]);

        $variant = $product->variants()->create([
            'name' => 'White',
            'options' => ['Colour' => 'White'],
            'sku' => 'W-1',
            'is_active' => true,
            'position' => 0,
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'image_path' => '/img/white.jpg',
            'is_primary' => true,
            'position' => 0,
        ]);

        $variant->delete();

        $this->assertSame(['/img/product.jpg'], $this->paths($product));
        $this->assertDatabaseMissing('product_images', ['image_path' => '/img/white.jpg']);
    }

    // ------------------------------------------------- an option's own gallery

    private function variantProduct(): Product
    {
        $product = $this->product();
        $product->forceFill([
            'has_variants' => true,
            'variant_attributes' => ['Colour'],
        ])->save();

        return $product;
    }

    public function test_an_option_can_have_several_photos(): void
    {
        $product = $this->variantProduct();

        $variant = $product->variants()->create([
            'name' => 'White',
            'options' => ['Colour' => 'White'],
            'sku' => 'W-1',
            'is_active' => true,
            'position' => 0,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'has_variants' => true,
                'variant_attributes' => ['Colour'],
                'variants' => [[
                    'id' => $variant->id,
                    'name' => 'White',
                    'options' => ['Colour' => 'White'],
                    'sku' => 'W-1',
                    'is_active' => true,
                    'images' => [
                        ['image_path' => '/img/white-front.jpg'],
                        ['image_path' => '/img/white-back.jpg'],
                    ],
                ]],
            ])
            ->assertOk();

        $this->assertSame(
            ['/img/white-front.jpg', '/img/white-back.jpg'],
            $variant->images()->pluck('image_path')->all(),
        );
    }

    /**
     * `image_url` stays the column the cart line, the order line and every
     * listing read. Options gain a gallery without any of those learning about
     * a new table, so it has to track the lead photo.
     */
    public function test_the_options_lead_photo_lands_on_image_url(): void
    {
        $product = $this->variantProduct();

        $variant = $product->variants()->create([
            'name' => 'Black',
            'options' => ['Colour' => 'Black'],
            'sku' => 'B-1',
            'is_active' => true,
            'position' => 0,
        ]);

        $payload = fn (array $images) => [
            'has_variants' => true,
            'variant_attributes' => ['Colour'],
            'variants' => [[
                'id' => $variant->id,
                'name' => 'Black',
                'options' => ['Colour' => 'Black'],
                'sku' => 'B-1',
                'is_active' => true,
                'images' => $images,
            ]],
        ];

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", $payload([
                ['image_path' => '/img/black-1.jpg'],
                ['image_path' => '/img/black-2.jpg'],
            ]))
            ->assertOk();

        $this->assertSame('/img/black-1.jpg', $variant->fresh()->image_url);

        // Promote the second, and image_url follows.
        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", $payload([
                ['image_path' => '/img/black-2.jpg', 'is_primary' => true],
                ['image_path' => '/img/black-1.jpg'],
            ]))
            ->assertOk();

        $this->assertSame('/img/black-2.jpg', $variant->fresh()->image_url);

        // Cleared, and it falls back to the product's photos on the page.
        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", $payload([]))
            ->assertOk();

        $this->assertNull($variant->fresh()->image_url);
    }

    /** Editing an option's price must not clear the photos taken for it. */
    public function test_omitting_an_options_gallery_leaves_it_alone(): void
    {
        $product = $this->variantProduct();

        $variant = $product->variants()->create([
            'name' => 'White',
            'options' => ['Colour' => 'White'],
            'sku' => 'W-1',
            'is_active' => true,
            'position' => 0,
        ]);

        $base = [
            'has_variants' => true,
            'variant_attributes' => ['Colour'],
        ];

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", $base + [
                'variants' => [[
                    'id' => $variant->id, 'name' => 'White',
                    'options' => ['Colour' => 'White'], 'sku' => 'W-1', 'is_active' => true,
                    'images' => [['image_path' => '/img/white.jpg']],
                ]],
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", $base + [
                'variants' => [[
                    'id' => $variant->id, 'name' => 'White',
                    'options' => ['Colour' => 'White'], 'sku' => 'W-1',
                    'is_active' => true, 'price' => 141000,
                ]],
            ])
            ->assertOk();

        $this->assertCount(1, $variant->images()->get());
    }
}
