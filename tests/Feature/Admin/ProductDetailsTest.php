<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The read-only product panel.
 *
 * The admin table ships a thin row on purpose — twenty a page, and the
 * category tree alone was 113 KB. So the only way to see what a product
 * actually was had been to open the edit form and read it out of the inputs: a
 * live form, one stray keystroke from a save, that still did not carry the
 * orders, reviews or branch holdings.
 */
class ProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(): Product
    {
        $brand = Brand::create(['name' => 'MSI', 'slug' => 'msi']);
        $parent = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $gaming = Category::create(['name' => 'Gaming Laptop', 'slug' => 'gaming-laptop', 'parent_id' => $parent->id, 'is_active' => true]);

        return Product::create([
            'category_id' => $gaming->id,
            'brand_id' => $brand->id,
            'name' => 'MSI Cyborg 15',
            'slug' => 'msi-cyborg-15',
            'model' => 'A13UC',
            'price' => 132000,
            'discount_price' => 125000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }

    public function test_it_returns_everything_the_table_leaves_out(): void
    {
        $product = $this->product();
        $extra = Category::create(['name' => 'All Laptop', 'slug' => 'all-laptop', 'is_active' => true]);
        $product->syncCategories([$extra->id]);

        $product->specifications()->create([
            'group' => 'Processor',
            'name' => 'Model',
            'value' => 'Core i5-13420H',
            'sort_order' => 0,
        ]);

        $data = $this->actingAs($this->admin())
            ->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('MSI Cyborg 15', $data['name']);
        $this->assertSame('MSI', $data['brand']['name']);
        $this->assertSame('Gaming Laptop', $data['category']['name']);
        $this->assertCount(2, $data['categories']);
        $this->assertSame('Core i5-13420H', $data['specifications'][0]['value']);

        // Counts the table has no room for and the edit form never had.
        foreach (['reviews_count', 'questions_count', 'order_items_count', 'stock_movements_count'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    /**
     * The price rules live in the model. Re-deriving them in JSX would be a
     * second set of rounding decisions that drifts from what the shop charges.
     */
    public function test_the_computed_price_fields_come_from_the_server(): void
    {
        $product = $this->product();

        $data = $this->actingAs($this->admin())
            ->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(125000.0, (float) $data['effective_price']);
        $this->assertSame(7000.0, (float) $data['saving']);
        $this->assertTrue($data['has_discount']);
        $this->assertTrue($data['discount_window_open']);
        $this->assertNotEmpty($data['stock_status_label']);
    }

    /**
     * Zero movements against a positive quantity is the state the panel exists
     * to make visible: stock nobody bought.
     */
    public function test_it_shows_when_a_quantity_has_no_history(): void
    {
        $product = $this->product();

        $data = $this->actingAs($this->admin())
            ->getJson("/api/admin/products/{$product->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(5, $data['stock_quantity']);
        $this->assertSame(0, $data['stock_movements_count']);
    }

    public function test_a_missing_product_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/products/999999')
            ->assertNotFound();
    }

    public function test_a_customer_cannot_read_it(): void
    {
        $product = $this->product();

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->getJson("/api/admin/products/{$product->id}")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_read_it(): void
    {
        $product = $this->product();

        $this->getJson("/api/admin/products/{$product->id}")
            ->assertUnauthorized();
    }
}
