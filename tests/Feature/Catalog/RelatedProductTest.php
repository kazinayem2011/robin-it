<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hand-picked "Similar Product" suggestions, and the Key Features list they sit
 * beside.
 *
 * Both are merchandising: what a shopper is shown when this product is not
 * quite right, and what they read before deciding it is. Neither can be derived
 * from the catalogue, which is why they are stored rather than computed.
 */
class RelatedProductTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true,
        ]);
    }

    private function product(string $name): Product
    {
        return Product::create([
            'category_id' => $this->category->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => 100000,
            'stock_quantity' => 2,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // --------------------------------------------------------- suggestions

    public function test_suggestions_keep_the_order_they_were_chosen_in(): void
    {
        $laptop = $this->product('MSI Cyborg 15');
        $first = $this->product('Lenovo LOQ 15');
        $second = $this->product('Asus TUF A15');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$laptop->id}", [
                'related_product_ids' => [$second->id, $first->id],
            ])
            ->assertOk();

        $this->assertSame(
            [$second->id, $first->id],
            $laptop->relatedProducts()->pluck('products.id')->all(),
            'Order is a merchandising decision, not an accident of insertion.'
        );
    }

    /**
     * A product suggesting itself sends a shopper to the page they are already
     * reading. Easy to do by accident in a multi-select of every product.
     */
    public function test_a_product_cannot_suggest_itself(): void
    {
        $laptop = $this->product('MSI Cyborg 15');
        $other = $this->product('Lenovo LOQ 15');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$laptop->id}", [
                'related_product_ids' => [$laptop->id, $other->id],
            ])
            ->assertOk();

        $ids = $laptop->relatedProducts()->pluck('products.id')->all();

        $this->assertSame([$other->id], $ids);
    }

    /**
     * Deliberately one-way. "Buy this cable with this monitor" is a useful
     * suggestion; the reverse would fill every cable's page with monitors.
     */
    public function test_suggestions_are_not_reciprocal(): void
    {
        $monitor = $this->product('Dell 27 inch Monitor');
        $cable = $this->product('HDMI 2.1 Cable');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$monitor->id}", [
                'related_product_ids' => [$cable->id],
            ])
            ->assertOk();

        $this->assertCount(1, $monitor->relatedProducts()->get());
        $this->assertCount(0, $cable->relatedProducts()->get());
    }

    public function test_sending_an_empty_list_clears_the_suggestions(): void
    {
        $laptop = $this->product('MSI Cyborg 15');
        $other = $this->product('Lenovo LOQ 15');
        $laptop->relatedProducts()->attach($other->id, ['position' => 0]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$laptop->id}", [
                'related_product_ids' => [],
            ])
            ->assertOk();

        $this->assertCount(0, $laptop->relatedProducts()->get());
    }

    /**
     * Absent is not the same as empty. Editing a price must not silently wipe
     * merchandising nobody touched.
     */
    public function test_omitting_the_field_leaves_suggestions_alone(): void
    {
        $laptop = $this->product('MSI Cyborg 15');
        $other = $this->product('Lenovo LOQ 15');
        $laptop->relatedProducts()->attach($other->id, ['position' => 0]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$laptop->id}", ['price' => 99000])
            ->assertOk();

        $this->assertCount(1, $laptop->relatedProducts()->get());
    }

    public function test_deleting_a_product_removes_it_from_other_pages(): void
    {
        $laptop = $this->product('MSI Cyborg 15');
        $other = $this->product('Lenovo LOQ 15');
        $laptop->relatedProducts()->attach($other->id, ['position' => 0]);

        $other->delete();

        $this->assertCount(0, $laptop->relatedProducts()->get());
        $this->assertDatabaseCount('product_related', 0);
    }

    // -------------------------------------------------------- key features

    public function test_key_features_are_stored_as_markup(): void
    {
        $laptop = $this->product('MSI Cyborg 15');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$laptop->id}", [
                'key_features' => '<ul><li>Intel Core i5-13420H</li><li>16GB DDR5</li></ul>',
            ])
            ->assertOk();

        $this->assertStringContainsString('<li>', $laptop->refresh()->key_features);
    }

    /**
     * Written by staff is not a reason to trust markup. The same person who
     * pastes a spec list from a manufacturer's site pastes whatever came with
     * it, and this renders on a public page.
     */
    public function test_key_features_are_sanitised(): void
    {
        $laptop = $this->product('MSI Cyborg 15');

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$laptop->id}", [
                'key_features' => '<ul><li>16GB DDR5</li></ul><script>alert(1)</script>',
            ])
            ->assertOk();

        $stored = $laptop->refresh()->key_features;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringContainsString('16GB DDR5', $stored);
    }

    public function test_the_sanitiser_is_the_same_one_the_description_uses(): void
    {
        $this->assertStringNotContainsString(
            '<script',
            RichText::clean('<p>ok</p><script>alert(1)</script>')
        );
    }
}
