<?php

namespace Tests\Feature\Admin;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Setting the answers a product gives, from the admin.
 *
 * Without this the filters are a thing only a seeder can populate, which is a
 * demonstration rather than a feature: nothing a shopkeeper enters would ever
 * be filterable.
 */
class ProductAttributeTest extends TestCase
{
    use RefreshDatabase;

    private Category $aisle;

    private Category $shelf;

    private Attribute $standard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aisle = Category::create(['name' => 'Router', 'slug' => 'router', 'is_active' => true]);
        $this->shelf = Category::create([
            'name' => 'TP-Link', 'slug' => 'router-tp-link',
            'parent_id' => $this->aisle->id, 'is_active' => true,
        ]);

        $this->standard = Attribute::create([
            'name' => 'Wi-Fi Standard', 'slug' => 'wi-fi-standard', 'input_type' => Attribute::ENUM,
        ]);

        foreach (['Wi-Fi 6', 'Wi-Fi 5'] as $i => $label) {
            AttributeValue::create([
                'attribute_id' => $this->standard->id,
                'label' => $label, 'slug' => Str::slug($label), 'sort_order' => $i,
            ]);
        }

        $this->standard->categories()->attach($this->aisle->id);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(): Product
    {
        return Product::create([
            'category_id' => $this->shelf->id,
            'name' => 'Archer AX10', 'slug' => 'archer-ax10',
            'price' => 5000, 'stock_quantity' => 2, 'is_active' => true,
        ]);
    }

    private function value(string $slug): AttributeValue
    {
        return AttributeValue::where('slug', $slug)->firstOrFail();
    }

    /** A shelf inherits its aisle's questions, so a brand shelf need not restate them. */
    public function test_a_shelf_is_told_the_questions_its_aisle_asks(): void
    {
        $this->actingAs($this->admin())
            ->getJson("/api/admin/categories/{$this->shelf->id}/attributes")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Wi-Fi Standard')
            ->assertJsonPath('data.0.values.0.label', 'Wi-Fi 6');
    }

    public function test_a_category_that_asks_nothing_returns_nothing(): void
    {
        $mouse = Category::create(['name' => 'Mouse', 'slug' => 'mouse', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->getJson("/api/admin/categories/{$mouse->id}/attributes")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_answer_can_be_set_on_a_product(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'attribute_value_ids' => [$this->value('wi-fi-6')->id],
            ])
            ->assertOk();

        $this->assertSame(['Wi-Fi 6'], $product->fresh()->attributeValues->pluck('label')->all());
    }

    /** Absent is not empty: editing a price must not strip a product's filters. */
    public function test_omitting_the_field_leaves_the_answers_alone(): void
    {
        $product = $this->product();
        $product->attributeValues()->sync([$this->value('wi-fi-6')->id]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['price' => 6000])
            ->assertOk();

        $this->assertCount(1, $product->fresh()->attributeValues);
    }

    /** An empty array is an instruction, though: the last box was unticked. */
    public function test_an_empty_list_clears_them(): void
    {
        $product = $this->product();
        $product->attributeValues()->sync([$this->value('wi-fi-6')->id]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", ['attribute_value_ids' => []])
            ->assertOk();

        $this->assertCount(0, $product->fresh()->attributeValues);
    }

    public function test_an_invented_value_is_refused(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'attribute_value_ids' => [999999],
            ])
            ->assertStatus(422);
    }

    public function test_a_customer_cannot_read_the_attribute_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->getJson("/api/admin/categories/{$this->shelf->id}/attributes")
            ->assertForbidden();
    }

    /** What is set in the admin is what the shop filters on. */
    public function test_setting_an_answer_makes_the_product_findable_by_it(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/products/{$product->id}", [
                'attribute_value_ids' => [$this->value('wi-fi-6')->id],
            ])
            ->assertOk();

        $found = app(ProductService::class)->getFilteredProducts([
            'category_slug' => 'router',
            'attributes' => ['wi-fi-standard' => ['wi-fi-6']],
        ]);

        $this->assertSame([$product->id], $found->pluck('id')->all());
    }
}
