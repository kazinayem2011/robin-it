<?php

namespace Tests\Feature\Catalog;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product's whole life, from the shelf it will stand on to the page a
 * customer reads.
 *
 * Every piece is a separate write — the row, its options, its photos, its
 * spec sheet, its attribute values, the shelves it is listed on, what it is
 * sold alongside, what it costs in fives — and any one of them can be dropped
 * without the others noticing. The create form in particular used to throw
 * away the options a shopkeeper had just filled in and answer 201.
 */
class ProductCycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private Category $root;

    private Category $shelf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->brand = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);

        $this->root = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $this->shelf = Category::create([
            'name' => 'Gaming Laptop', 'slug' => 'gaming-laptop',
            'parent_id' => $this->root->id, 'is_active' => true,
        ]);
    }

    private function asAdmin(): self
    {
        $this->actingAs($this->admin);

        return $this;
    }

    /** Everything a shopkeeper can type, filled in. */
    private function fullPayload(array $extra = []): array
    {
        return array_merge([
            'category_id' => $this->shelf->id,
            'brand_id' => $this->brand->id,
            'name' => 'ASUS ROG Strix G16 Core i9 14900HX RTX 4070 16in QHD 240Hz',
            'model' => 'G614JIR-N4090W',
            'mpn' => '90NR0IF4-M004K0',
            'barcode' => '4711387432198',
            'price' => 285000,
            'discount_price' => 269000,
            'short_description' => trim(str_repeat('A fast machine for games and work. ', 12)),
            'key_features' => "Core i9-14900HX\nRTX 4070 8GB\n16in QHD+ 240Hz\n32GB DDR5\n1TB NVMe",
            'description' => str_repeat('<p>'.str_repeat('Long form copy about this laptop. ', 40).'</p>', 6),
            'meta_title' => 'ASUS ROG Strix G16 Price in Bangladesh',
            'meta_description' => trim(str_repeat('Buy the ROG Strix G16 at a good price. ', 6)),
            'meta_keyword' => 'asus, rog, strix, gaming laptop, rtx 4070',
            'warranty_months' => 24,
            'warranty_text' => "2 years on the unit\nBattery and adapter: 1 year\nPhysical damage is not covered",
            'emi_available' => true,
            'emi_max_months' => 12,
            'min_order_quantity' => 1,
            'reorder_level' => 3,
            'is_featured' => true,
        ], $extra);
    }

    private function create(array $payload): Product
    {
        $this->asAdmin()->postJson('/api/admin/products', $payload)->assertCreated();

        return Product::latest('id')->firstOrFail();
    }

    // ── brands ───────────────────────────────────────────────────────────────

    public function test_a_brand_is_created_and_can_be_renamed(): void
    {
        $this->asAdmin()->postJson('/api/admin/brands', ['name' => 'MSI'])->assertSuccessful();

        $brand = Brand::where('name', 'MSI')->firstOrFail();
        $this->assertNotEmpty($brand->slug);

        $this->asAdmin()->patchJson("/api/admin/brands/{$brand->id}", ['name' => 'MSI Global'])
            ->assertSuccessful();

        $this->assertSame('MSI Global', $brand->fresh()->name);
    }

    public function test_two_brands_cannot_share_a_name(): void
    {
        $this->asAdmin()->postJson('/api/admin/brands', ['name' => 'ASUS'])->assertStatus(422);
    }

    // ── categories ───────────────────────────────────────────────────────────

    public function test_a_shelf_is_created_under_another_and_can_be_renamed(): void
    {
        $this->asAdmin()->postJson('/api/admin/categories', [
            'name' => 'Ultrabook', 'parent_id' => $this->root->id,
        ])->assertCreated();

        $made = Category::where('name', 'Ultrabook')->firstOrFail();
        $this->assertSame($this->root->id, $made->parent_id);

        $this->asAdmin()->patchJson("/api/admin/categories/{$made->id}", [
            'name' => 'Premium Ultrabook', 'parent_id' => $this->root->id,
        ])->assertOk();

        $this->assertSame('Premium Ultrabook', $made->fresh()->name);
    }

    public function test_a_shelf_standing_for_a_brand_is_addressed_maker_first(): void
    {
        $this->asAdmin()->postJson('/api/admin/categories', [
            'name' => 'ASUS', 'parent_id' => $this->shelf->id, 'brand_id' => $this->brand->id,
        ])->assertCreated();

        $this->assertDatabaseHas('categories', ['slug' => 'asus-gaming-laptop']);
    }

    /** products.category_id cascades, so this refusal is what saves them. */
    public function test_a_shelf_holding_products_refuses_to_be_deleted(): void
    {
        $this->create($this->fullPayload());

        $this->asAdmin()->deleteJson("/api/admin/categories/{$this->shelf->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('categories', ['id' => $this->shelf->id]);
    }

    public function test_an_empty_shelf_is_deleted(): void
    {
        $empty = Category::create([
            'name' => 'Empty', 'slug' => 'empty', 'parent_id' => $this->root->id, 'is_active' => true,
        ]);

        $this->asAdmin()->deleteJson("/api/admin/categories/{$empty->id}")->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
    }

    // ── a single product, every field filled ─────────────────────────────────

    public function test_a_product_keeps_every_word_it_was_given(): void
    {
        $payload = $this->fullPayload();
        $product = $this->create($payload);

        foreach ([
            'name', 'model', 'mpn', 'barcode', 'short_description',
            'key_features', 'description', 'meta_title', 'meta_description',
            'meta_keyword', 'warranty_text',
        ] as $field) {
            $this->assertSame($payload[$field], $product->$field, "{$field} was not kept");
        }

        $this->assertEquals(285000, $product->price);
        $this->assertEquals(269000, $product->discount_price);
        $this->assertSame(24, (int) $product->warranty_months);
        $this->assertSame(12, (int) $product->emi_max_months);
        $this->assertTrue((bool) $product->emi_available);
        $this->assertTrue((bool) $product->is_featured);
        $this->assertNotEmpty($product->slug);
    }

    /** Long copy is the normal case for a laptop, not an edge one. */
    public function test_a_description_of_several_thousand_characters_survives(): void
    {
        // Trimmed, because Laravel's TrimStrings takes the trailing space
        // off every input before a controller ever sees it.
        $long = trim(str_repeat('Every specification, spelled out at length. ', 900));

        $product = $this->create($this->fullPayload(['description' => $long]));

        $this->assertSame(strlen($long), strlen($product->description));
    }

    /** Stock arrives under Purchasing; the form cannot set it. */
    public function test_a_new_product_starts_with_no_stock(): void
    {
        $product = $this->create($this->fullPayload(['stock_quantity' => 500]));

        $this->assertSame(0, (int) $product->stock_quantity);
    }

    public function test_a_product_carries_its_spec_sheet(): void
    {
        $product = $this->create($this->fullPayload([
            'specifications' => [
                ['group' => 'Processor', 'name' => 'Model', 'value' => 'Core i9-14900HX'],
                ['group' => 'Processor', 'name' => 'Cores', 'value' => '24'],
                ['group' => 'Display', 'name' => 'Size', 'value' => '16 inch'],
            ],
        ]));

        $this->assertCount(3, $product->specifications);
        $this->assertSame('Core i9-14900HX', $product->specifications->firstWhere('name', 'Model')->value);
    }

    public function test_a_product_is_listed_on_more_than_one_shelf(): void
    {
        $second = Category::create([
            'name' => 'All Laptop', 'slug' => 'all-laptop',
            'parent_id' => $this->root->id, 'is_active' => true,
        ]);

        $product = $this->create($this->fullPayload([
            'category_ids' => [$this->shelf->id, $second->id],
        ]));

        $this->assertEqualsCanonicalizing(
            [$this->shelf->id, $second->id],
            $product->categories->pluck('id')->all(),
        );
    }

    public function test_a_product_takes_the_attribute_values_that_filter_it(): void
    {
        $attribute = Attribute::create(['name' => 'RAM Size', 'slug' => 'ram-size', 'input_type' => 'number']);
        $attribute->categories()->attach($this->root->id);
        $value = $attribute->values()->create(['label' => '32 GB', 'slug' => '32-gb']);

        $product = $this->create($this->fullPayload(['attribute_value_ids' => [$value->id]]));

        $this->assertSame([$value->id], $product->attributeValues->pluck('id')->all());
    }

    public function test_a_product_remembers_what_it_is_sold_alongside(): void
    {
        $other = $this->create($this->fullPayload(['name' => 'A Bag', 'barcode' => null]));

        $product = $this->create($this->fullPayload([
            'name' => 'The Laptop', 'barcode' => null,
            'related_product_ids' => [$other->id],
        ]));

        $this->assertSame([$other->id], $product->relatedProducts->pluck('id')->all());
    }

    public function test_a_product_can_be_cheaper_by_the_handful(): void
    {
        $product = $this->create($this->fullPayload([
            'quantity_discounts' => [
                ['min_quantity' => 5, 'price' => 260000],
                ['min_quantity' => 10, 'price' => 250000],
            ],
        ]));

        $this->assertCount(2, $product->quantityDiscounts);
    }

    // ── a product sold in options ────────────────────────────────────────────

    public function test_a_product_can_be_entered_with_its_options_in_one_go(): void
    {
        $product = $this->create($this->fullPayload([
            'has_variants' => true,
            'variant_attributes' => ['RAM', 'Storage'],
            'variants' => [
                ['name' => '16GB / 512GB', 'sku' => 'ROG-16-512', 'price' => 285000,
                    'options' => ['RAM' => '16GB', 'Storage' => '512GB'], 'opening_stock' => 4, 'is_active' => true],
                ['name' => '32GB / 1TB', 'sku' => 'ROG-32-1T', 'price' => 315000,
                    'options' => ['RAM' => '32GB', 'Storage' => '1TB'], 'opening_stock' => 2, 'is_active' => true],
            ],
        ]));

        $this->assertTrue((bool) $product->has_variants);
        $this->assertCount(2, $product->variants);
        $this->assertEqualsCanonicalizing(
            ['ROG-16-512', 'ROG-32-1T'],
            $product->variants->pluck('sku')->all(),
        );
        $this->assertEquals(315000, $product->variants->firstWhere('sku', 'ROG-32-1T')->price);
    }

    /**
     * A product is made empty whatever the form says, options included.
     *
     * The options describe what the product is sold as; what is on the shelf
     * against each of them arrives under Purchasing, from a supplier or an
     * opening balance, so a figure typed here is deliberately dropped.
     */
    public function test_a_product_in_options_still_starts_with_no_stock(): void
    {
        $product = $this->create($this->fullPayload([
            'has_variants' => true,
            'variant_attributes' => ['RAM'],
            'variants' => [
                ['name' => '16GB', 'sku' => 'A-16', 'price' => 285000, 'options' => ['RAM' => '16GB'], 'opening_stock' => 4, 'is_active' => true],
                ['name' => '32GB', 'sku' => 'A-32', 'price' => 315000, 'options' => ['RAM' => '32GB'], 'opening_stock' => 3, 'is_active' => true],
            ],
        ]));

        $this->assertSame(0, (int) $product->fresh()->stock_quantity);
        $this->assertSame(
            [0, 0],
            $product->variants->pluck('stock_quantity')->map(fn ($n) => (int) $n)->all(),
        );
    }

    /**
     * The code is unique across the whole table, so two options typed with the
     * same one reached the insert and came back a 500.
     */
    public function test_two_options_cannot_share_a_code(): void
    {
        $this->asAdmin()->postJson('/api/admin/products', $this->twinSku())
            ->assertStatus(422);
    }

    /** Nor two barcodes, for the same reason. */
    public function test_two_options_cannot_share_a_barcode(): void
    {
        $this->asAdmin()->postJson('/api/admin/products', $this->fullPayload([
            'has_variants' => true,
            'variant_attributes' => ['RAM'],
            'variants' => [
                ['name' => '16GB', 'sku' => 'A', 'barcode' => 'TWIN', 'price' => 1, 'options' => ['RAM' => '16GB'], 'is_active' => true],
                ['name' => '32GB', 'sku' => 'B', 'barcode' => 'TWIN', 'price' => 2, 'options' => ['RAM' => '32GB'], 'is_active' => true],
            ],
        ]))->assertStatus(422);
    }

    /**
     * And the part that made it more than a bad error page: the product row
     * was written before the options were, so a refused insert left a live
     * item on the storefront with no options on it — and the shopkeeper,
     * shown a server error, made a second one.
     */
    public function test_a_refused_option_leaves_no_product_behind(): void
    {
        $this->asAdmin()->postJson('/api/admin/products', $this->twinSku(['name' => 'Half Made']));

        $this->assertDatabaseMissing('products', ['name' => 'Half Made']);
        $this->assertSame(0, ProductVariant::count());
    }

    /** And a code already on another product's option is refused too. */
    public function test_a_code_already_on_another_option_is_refused(): void
    {
        $this->create($this->fullPayload([
            'name' => 'First', 'barcode' => null,
            'has_variants' => true, 'variant_attributes' => ['RAM'],
            'variants' => [
                ['name' => '16GB', 'sku' => 'TAKEN', 'price' => 1, 'options' => ['RAM' => '16GB'], 'is_active' => true],
            ],
        ]));

        $this->asAdmin()->postJson('/api/admin/products', $this->fullPayload([
            'name' => 'Second', 'barcode' => null,
            'has_variants' => true, 'variant_attributes' => ['RAM'],
            'variants' => [
                ['name' => '32GB', 'sku' => 'TAKEN', 'price' => 2, 'options' => ['RAM' => '32GB'], 'is_active' => true],
            ],
        ]))->assertStatus(422);
    }

    /** The same code in different case is the same code. */
    public function test_a_code_repeated_in_another_case_is_refused(): void
    {
        $this->asAdmin()->postJson('/api/admin/products', $this->fullPayload([
            'has_variants' => true, 'variant_attributes' => ['RAM'],
            'variants' => [
                ['name' => '16GB', 'sku' => 'rog-16', 'price' => 1, 'options' => ['RAM' => '16GB'], 'is_active' => true],
                ['name' => '32GB', 'sku' => 'ROG-16', 'price' => 2, 'options' => ['RAM' => '32GB'], 'is_active' => true],
            ],
        ]))->assertStatus(422);
    }

    private function twinSku(array $extra = []): array
    {
        return $this->fullPayload(array_merge([
            'has_variants' => true,
            'variant_attributes' => ['RAM'],
            'variants' => [
                ['name' => '16GB', 'sku' => 'SAME', 'price' => 1, 'options' => ['RAM' => '16GB'], 'is_active' => true],
                ['name' => '32GB', 'sku' => 'SAME', 'price' => 2, 'options' => ['RAM' => '32GB'], 'is_active' => true],
            ],
        ], $extra));
    }

    // ── what the storefront then shows ───────────────────────────────────────

    public function test_the_product_reaches_the_shop(): void
    {
        $product = $this->create($this->fullPayload());
        $product->update(['stock_quantity' => 5]);

        $this->getJson("/api/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.name', $product->name)
            ->assertJsonPath('data.model', 'G614JIR-N4090W');

        $listing = $this->getJson('/api/products?category_slug=gaming-laptop')->assertOk();

        $this->assertContains(
            $product->name,
            collect($listing->json('data.data') ?? $listing->json('data'))->pluck('name')->all(),
        );
    }

    // ── what the form refuses ────────────────────────────────────────────────

    public function test_a_barcode_is_not_used_twice(): void
    {
        $this->create($this->fullPayload());

        $this->asAdmin()->postJson('/api/admin/products', $this->fullPayload(['name' => 'Another']))
            ->assertStatus(422);
    }

    public function test_a_shelf_that_does_not_exist_is_refused(): void
    {
        $this->asAdmin()->postJson('/api/admin/products', $this->fullPayload(['category_id' => 999999]))
            ->assertStatus(422);
    }

    public function test_a_price_below_zero_is_refused(): void
    {
        $this->asAdmin()->postJson('/api/admin/products', $this->fullPayload(['price' => -1]))
            ->assertStatus(422);
    }

    public function test_a_name_is_required(): void
    {
        $payload = $this->fullPayload();
        unset($payload['name']);

        $this->asAdmin()->postJson('/api/admin/products', $payload)->assertStatus(422);
    }

    public function test_a_shopper_cannot_add_a_product(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/api/admin/products', $this->fullPayload())
            ->assertStatus(403);
    }
}
