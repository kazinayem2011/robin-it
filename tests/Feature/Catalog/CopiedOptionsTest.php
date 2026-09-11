<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saving a copied product that is sold in options.
 *
 * A copy clears each option's stock code and barcode, because both are unique
 * across the whole shop. Cleared means blank on a form, and a blank text input
 * posts an empty string rather than nothing — while the uniqueness underneath
 * is a database index, which treats two empty strings as a collision and two
 * nulls as neither. So a copy of a product with more than one option is the
 * case where the difference between '' and null stops being pedantry.
 */
class CopiedOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $variants): array
    {
        $shelf = Category::firstOrCreate(
            ['slug' => 'ram'],
            ['name' => 'RAM', 'is_active' => true],
        );

        return [
            'name' => 'Kingston Fury (Copy)',
            'category_id' => $shelf->id,
            'price' => 8000,
            'has_variants' => true,
            'variant_attributes' => ['Capacity'],
            'variants' => $variants,
        ];
    }

    public function test_a_copy_with_several_blank_stock_codes_saves(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/products', $this->payload([
                ['name' => '16GB', 'sku' => '', 'barcode' => '', 'price' => 8000, 'options' => ['Capacity' => '16GB'], 'is_active' => true],
                ['name' => '32GB', 'sku' => '', 'barcode' => '', 'price' => 15000, 'options' => ['Capacity' => '32GB'], 'is_active' => true],
                ['name' => '64GB', 'sku' => '', 'barcode' => '', 'price' => 28000, 'options' => ['Capacity' => '64GB'], 'is_active' => true],
            ]))
            ->assertStatus(201);

        $this->assertSame(3, ProductVariant::count());
    }

    /**
     * Blank has to reach the column as null, or the second option collides
     * with the first on an index that is doing exactly what it was added for.
     */
    public function test_a_blank_code_is_stored_as_nothing_rather_than_as_empty(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/products', $this->payload([
                ['name' => '16GB', 'sku' => '', 'barcode' => '', 'price' => 8000, 'options' => ['Capacity' => '16GB'], 'is_active' => true],
            ]))
            ->assertStatus(201);

        $variant = ProductVariant::sole();

        $this->assertNull($variant->sku);
        $this->assertNull($variant->barcode);
    }

    /** And a copy of a single-stock product, whose own barcode is cleared. */
    public function test_two_products_may_both_have_no_barcode(): void
    {
        $shelf = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['First', 'Second'] as $name) {
            $this->actingAs($admin)->postJson('/api/admin/products', [
                'name' => $name,
                'category_id' => $shelf->id,
                'price' => 1000,
                'barcode' => '',
            ])->assertStatus(201);
        }

        $this->assertSame(2, Product::count());
        $this->assertSame(2, Product::whereNull('barcode')->count());
    }
}
