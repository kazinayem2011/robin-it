<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product with no brand.
 *
 * Three screens gave three answers for the same field on the same product: the
 * edit form showed "Intel", the table showed "Standard", the details panel
 * showed "Not set". Only the last was true — every product in the shop had a
 * null brand_id.
 *
 * The form was the dangerous one. It defaulted to `brands[0]`, so opening any
 * brandless product preselected whichever brand happened to be first in the
 * table, and saving anything at all — a price, a typo in the name — filed the
 * product under it for good.
 */
class ProductBrandTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function category(): Category
    {
        return Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
    }

    public function test_a_product_can_be_saved_with_no_brand(): void
    {
        $this->admin();
        Brand::create(['name' => 'Intel', 'slug' => 'intel']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', [
                'name' => 'MSI Cyborg 15',
                'category_id' => $this->category()->id,
                'brand_id' => '',
                'price' => 132000,
                'stock_quantity' => 0,
            ])
            ->assertCreated();

        $this->assertNull(Product::firstWhere('name', 'MSI Cyborg 15')->brand_id);
    }

    /**
     * The bug itself: editing anything else must not attach a brand the shop
     * never chose.
     */
    public function test_editing_another_field_leaves_a_brandless_product_brandless(): void
    {
        Brand::create(['name' => 'Intel', 'slug' => 'intel']);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'name' => 'MSI Cyborg 15',
            'slug' => 'msi-cyborg-15',
            'price' => 132000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['price' => 129000])
            ->assertOk();

        $this->assertNull($product->fresh()->brand_id);
    }

    /** And a brand deliberately cleared is cleared, not ignored. */
    public function test_a_brand_can_be_removed_from_a_product(): void
    {
        $brand = Brand::create(['name' => 'Intel', 'slug' => 'intel']);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'brand_id' => $brand->id,
            'name' => 'MSI Cyborg 15',
            'slug' => 'msi-cyborg-15',
            'price' => 132000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['brand_id' => ''])
            ->assertOk();

        $this->assertNull($product->fresh()->brand_id);
    }

    public function test_a_brand_can_still_be_set(): void
    {
        $brand = Brand::create(['name' => 'MSI', 'slug' => 'msi']);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'name' => 'MSI Cyborg 15',
            'slug' => 'msi-cyborg-15',
            'price' => 132000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['brand_id' => $brand->id])
            ->assertOk();

        $this->assertSame($brand->id, $product->fresh()->brand_id);
    }

    /** The details panel was the one screen telling the truth. Keep it that way. */
    public function test_the_details_panel_reports_no_brand_rather_than_guessing(): void
    {
        Brand::create(['name' => 'Intel', 'slug' => 'intel']);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'name' => 'MSI Cyborg 15',
            'slug' => 'msi-cyborg-15',
            'price' => 132000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $data = $this->actingAs($this->admin())
            ->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->json('data');

        $this->assertNull($data['brand']);
        $this->assertNull($data['brand_id']);
    }
}
