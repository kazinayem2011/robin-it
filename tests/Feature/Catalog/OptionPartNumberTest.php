<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each option can carry the maker's part number, as StarTech shows it: a
 * phone's 4GB/64GB and 4GB/128GB are two MPNs, and choosing one swaps it.
 */
class OptionPartNumberTest extends TestCase
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
        $category = Category::create(['name' => 'Phone', 'slug' => 'phone', 'is_active' => true]);

        $this->actingAs($this->admin)->postJson('/api/admin/products', [
            'name' => 'Realme C100i',
            'category_id' => $category->id,
            'price' => 17999,
            'is_active' => true,
            'has_variants' => true,
            'variant_attributes' => ['RAM / Storage'],
            'variants' => [
                ['options' => ['RAM / Storage' => '4GB/64GB'], 'sku' => 'C100I-64', 'mpn' => ' RMX5001 ', 'price' => 17999, 'is_active' => true],
                ['options' => ['RAM / Storage' => '4GB/128GB'], 'sku' => 'C100I-128', 'mpn' => '', 'price' => 19999, 'is_active' => true],
            ],
        ])->assertCreated();

        return Product::firstWhere('name', 'Realme C100i');
    }

    public function test_an_option_keeps_its_part_number(): void
    {
        $product = $this->create();

        $this->assertSame('RMX5001', $product->variants()->where('sku', 'C100I-64')->value('mpn'));
        $this->assertNull($product->variants()->where('sku', 'C100I-128')->value('mpn'));

        $options = collect($this->getJson("/api/products/{$product->slug}")->assertOk()->json('data.active_variants'));
        $this->assertSame('RMX5001', $options->firstWhere('sku', 'C100I-64')['mpn'] ?? null);
    }

    /** An edit that does not mention it leaves it alone. */
    public function test_an_edit_that_leaves_it_out_keeps_it(): void
    {
        $product = $this->create();
        $ids = $product->variants()->orderBy('position')->pluck('id');

        $this->actingAs($this->admin)->patchJson("/api/admin/products/{$product->id}", [
            'variant_attributes' => ['RAM / Storage'],
            'variants' => [
                ['id' => $ids[0], 'options' => ['RAM / Storage' => '4GB/64GB'], 'sku' => 'C100I-64', 'price' => 16999, 'is_active' => true],
                ['id' => $ids[1], 'options' => ['RAM / Storage' => '4GB/128GB'], 'sku' => 'C100I-128', 'price' => 18999, 'is_active' => true],
            ],
        ])->assertOk();

        $this->assertSame('RMX5001', $product->variants()->where('sku', 'C100I-64')->value('mpn'));
    }
}
