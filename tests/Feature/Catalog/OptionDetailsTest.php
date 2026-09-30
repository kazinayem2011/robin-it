<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An option carries what differs on it: its own key features and spec rows.
 *
 * A laptop sold as Core i5 and Core i7 builds changed only its price when the
 * shopper picked one; the key features and the spec table went on describing
 * whichever build they were written for.
 */
class OptionDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function create(): Product
    {
        $category = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);

        $this->actingAs($this->admin)->postJson('/api/admin/products', [
            'name' => 'Lenovo IdeaPad Slim 3',
            'category_id' => $category->id,
            'price' => 105600,
            'is_active' => true,
            'key_features' => '<ul><li>Shared</li></ul>',
            'specifications' => [
                ['group' => 'Processor', 'name' => 'Processor Model', 'value' => 'Core i5 or Core i7'],
            ],
            'has_variants' => true,
            'variant_attributes' => ['Processor'],
            'variants' => [
                ['options' => ['Processor' => 'Core i5-13420H'], 'sku' => 'I5', 'price' => 105600, 'is_active' => true],
                [
                    'options' => ['Processor' => 'Core i7-13620H'], 'sku' => 'I7', 'price' => 115550, 'is_active' => true,
                    'key_features' => '<ul><li>Processor: Intel Core i7-13620H</li><script>alert(1)</script></ul>',
                    'specifications' => [
                        ['group' => 'Processor', 'name' => 'Processor Model', 'value' => 'Core i7-13620H'],
                        ['group' => 'Camera', 'name' => 'WebCam', 'value' => 'FHD 1080p + IR'],
                        ['group' => '', 'name' => '', 'value' => 'dropped: no name'],
                    ],
                ],
            ],
        ])->assertCreated();

        return Product::firstWhere('name', 'Lenovo IdeaPad Slim 3');
    }

    public function test_an_option_keeps_its_own_key_features_and_spec_rows(): void
    {
        $product = $this->create();
        $i5 = $product->variants()->where('sku', 'I5')->first();
        $i7 = $product->variants()->where('sku', 'I7')->first();

        $this->assertNull($i5->key_features);
        $this->assertNull($i5->specifications);

        $this->assertStringContainsString('Core i7-13620H', $i7->key_features);
        $this->assertStringNotContainsString('<script', $i7->key_features);
        $this->assertSame([
            ['group' => 'Processor', 'name' => 'Processor Model', 'value' => 'Core i7-13620H'],
            ['group' => 'Camera', 'name' => 'WebCam', 'value' => 'FHD 1080p + IR'],
        ], $i7->specifications);
    }

    public function test_the_shop_page_receives_them(): void
    {
        $product = $this->create();

        $variants = collect($this->getJson("/api/products/{$product->slug}")->assertOk()->json('data.active_variants'));
        $i7 = $variants->firstWhere('sku', 'I7') ?? $variants->firstWhere('name', 'Core i7-13620H');

        $this->assertSame('FHD 1080p + IR', collect($i7['specifications'])->firstWhere('name', 'WebCam')['value']);
        $this->assertStringContainsString('Core i7-13620H', $i7['key_features']);
    }

    /** An edit that does not mention them leaves them alone. */
    public function test_an_edit_that_leaves_them_out_keeps_them(): void
    {
        $product = $this->create();
        $i7 = $product->variants()->where('sku', 'I7')->first();

        $this->actingAs($this->admin)->patchJson("/api/admin/products/{$product->id}", [
            'variant_attributes' => ['Processor'],
            'variants' => [
                ['id' => $product->variants()->where('sku', 'I5')->value('id'), 'options' => ['Processor' => 'Core i5-13420H'], 'sku' => 'I5', 'price' => 99000, 'is_active' => true],
                ['id' => $i7->id, 'options' => ['Processor' => 'Core i7-13620H'], 'sku' => 'I7', 'price' => 110000, 'is_active' => true],
            ],
        ])->assertOk();

        $i7->refresh();
        $this->assertSame(110000.0, $i7->price);
        $this->assertCount(2, $i7->specifications);
    }
}
