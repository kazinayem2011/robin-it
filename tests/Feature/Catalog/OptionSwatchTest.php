<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A colour option carries the colour it is, so a shopper choosing between
 * Natural and Blue Titanium sees them rather than reading them.
 */
class OptionSwatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function payload(array $variants): array
    {
        return [
            'name' => 'iPhone 15 Pro Max',
            'category_id' => Category::firstOrCreate(['slug' => 'phone'], ['name' => 'Phone', 'is_active' => true])->id,
            'price' => 279999,
            'is_active' => true,
            'has_variants' => true,
            'variant_attributes' => ['RAM / Storage', 'Color'],
            'variants' => $variants,
        ];
    }

    private function option(string $colour, ?string $swatch, string $sku): array
    {
        return ['options' => ['RAM / Storage' => '512GB', 'Color' => $colour], 'sku' => $sku, 'swatch' => $swatch, 'price' => 206500, 'is_active' => true];
    }

    public function test_an_option_keeps_its_swatch_and_the_shop_is_sent_it(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/products', $this->payload([
            $this->option('Blue Titanium', '#3D5A80', 'IP-BLUE'),
            $this->option('Natural Titanium', null, 'IP-NAT'),
        ]))->assertCreated();

        $product = Product::firstWhere('name', 'iPhone 15 Pro Max');

        $this->assertSame('#3d5a80', $product->variants()->where('sku', 'IP-BLUE')->value('swatch'));
        $this->assertNull($product->variants()->where('sku', 'IP-NAT')->value('swatch'));

        $options = collect($this->getJson("/api/products/{$product->slug}")->assertOk()->json('data.active_variants'));
        $this->assertSame('#3d5a80', $options->firstWhere('sku', 'IP-BLUE')['swatch'] ?? null);
    }

    public function test_only_a_colour_is_taken(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/products', $this->payload([
            $this->option('Blue Titanium', 'blue', 'IP-BLUE'),
        ]))->assertStatus(422);

        $this->assertSame(0, Product::count());
    }

    /** An edit that does not mention it leaves it alone. */
    public function test_an_edit_that_leaves_it_out_keeps_it(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/products', $this->payload([
            $this->option('Blue Titanium', '#3d5a80', 'IP-BLUE'),
        ]))->assertCreated();

        $product = Product::firstWhere('name', 'iPhone 15 Pro Max');
        $option = $this->option('Blue Titanium', null, 'IP-BLUE');
        unset($option['swatch']);

        $this->actingAs($this->admin)->patchJson("/api/admin/products/{$product->id}", [
            'variant_attributes' => ['RAM / Storage', 'Color'],
            'variants' => [['id' => $product->variants()->value('id')] + $option],
        ])->assertOk();

        $this->assertSame('#3d5a80', $product->variants()->value('swatch'));
    }
}
