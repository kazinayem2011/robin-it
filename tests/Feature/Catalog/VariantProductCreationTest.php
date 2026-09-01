<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entering a product that is sold in options, in one go.
 *
 * The options editor has always been on the create form. Nothing read it:
 * `store()` never touched `has_variants` or `variants`, and the create request
 * did not even validate them, so `validated()` dropped them on the floor. The
 * request came back 201 with a success toast, the product saved as a single-
 * stock item, and the shopkeeper had to open it again and enter the options a
 * second time.
 */
class VariantProductCreationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function category(): Category
    {
        return Category::create(['name' => 'RAM', 'slug' => 'ram', 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Corsair Vengeance DDR5',
            'category_id' => $this->category()->id,
            'price' => 10000,
            'stock_quantity' => 0,
            'has_variants' => true,
            'variant_attributes' => ['Capacity'],
            'variants' => [
                ['name' => '16GB', 'options' => ['Capacity' => '16GB'], 'sku' => 'CV-16', 'price' => 10000, 'is_active' => true],
                ['name' => '32GB', 'options' => ['Capacity' => '32GB'], 'sku' => 'CV-32', 'price' => 18000, 'is_active' => true],
            ],
        ], $overrides);
    }

    public function test_a_product_can_be_created_with_its_options(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload())
            ->assertCreated();

        $product = Product::firstWhere('name', 'Corsair Vengeance DDR5');

        $this->assertTrue($product->has_variants);
        $this->assertSame(['Capacity'], $product->variant_attributes);
        $this->assertSame(2, $product->variants()->count());
        $this->assertEqualsCanonicalizing(
            ['CV-16', 'CV-32'],
            $product->variants()->pluck('sku')->all(),
        );
    }

    /** A single product is still a single product — nothing switches by accident. */
    public function test_leaving_options_off_still_creates_a_single_product(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'has_variants' => false,
                'variants' => [],
            ]))
            ->assertCreated();

        $product = Product::firstWhere('name', 'Corsair Vengeance DDR5');

        $this->assertFalse($product->has_variants);
        $this->assertSame(0, $product->variants()->count());
    }

    /**
     * A new product's options start empty, whatever is sent.
     *
     * Stock the shop already holds is received under Purchasing from the
     * "Opening balance" source — one way in, with a document behind it.
     */
    public function test_the_options_of_a_new_product_start_empty(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'stock_quantity' => 9,
                'variants' => [
                    ['name' => '16GB', 'options' => ['Capacity' => '16GB'], 'sku' => 'CV-16', 'is_active' => true, 'opening_stock' => 3],
                    ['name' => '32GB', 'options' => ['Capacity' => '32GB'], 'sku' => 'CV-32', 'is_active' => true, 'opening_stock' => 2],
                ],
            ]))
            ->assertCreated();

        $product = Product::firstWhere('name', 'Corsair Vengeance DDR5');

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame([0, 0], $product->variants()->orderBy('position')->pluck('stock_quantity')->all());
    }

    /** Each option's photos are saved with it, not on the next edit. */
    public function test_option_photos_are_saved_at_creation(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'variants' => [
                    [
                        'name' => '16GB', 'options' => ['Capacity' => '16GB'], 'sku' => 'CV-16', 'is_active' => true,
                        'images' => [
                            ['image_path' => '/img/16gb-front.jpg'],
                            ['image_path' => '/img/16gb-back.jpg'],
                        ],
                    ],
                ],
            ]))
            ->assertCreated();

        $variant = Product::firstWhere('name', 'Corsair Vengeance DDR5')->variants()->sole();

        $this->assertSame(
            ['/img/16gb-front.jpg', '/img/16gb-back.jpg'],
            $variant->images()->pluck('image_path')->all(),
        );
        $this->assertSame('/img/16gb-front.jpg', $variant->image_url);
    }

    /** Creating a product writes nothing to the ledger at all. */
    public function test_creating_a_product_writes_no_movement(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'variants' => [
                    ['name' => '16GB', 'options' => ['Capacity' => '16GB'], 'sku' => 'CV-16', 'is_active' => true, 'opening_stock' => 4],
                ],
            ]))
            ->assertCreated();

        $product = Product::firstWhere('name', 'Corsair Vengeance DDR5');

        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }
}
