<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether a new product goes straight onto the storefront.
 *
 * It always did, and nothing on the form could stop it: `is_active` was absent
 * from ProductStoreRequest, so it never survived validation, and store() then
 * set it to true outright. The "Active in Live Storefront" checkbox has never
 * had any effect on a product being created — unticking it changed nothing.
 *
 * That matters because the form encourages saving early: stock cannot be
 * received against a product that does not exist yet, so a product entered
 * across six tabs was published the moment the first tab was filled, with no
 * photograph, no spec sheet and no filter answers. A product answering no
 * filters is invisible to the sidebar, so the shoppers who do reach it are
 * the ones who searched its exact name, and what they find is a placeholder.
 */
class ProductDraftTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function shelf(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'laptop'],
            ['name' => 'Laptop', 'is_active' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ASUS TUF Gaming A15',
            'category_id' => $this->shelf()->id,
            'price' => 145000,
        ], $overrides);
    }

    public function test_a_product_saved_as_a_draft_stays_off_the_storefront(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload(['is_active' => false]))
            ->assertStatus(201);

        $this->assertFalse((bool) Product::sole()->is_active);
    }

    public function test_a_product_saved_as_published_goes_live(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload(['is_active' => true]))
            ->assertStatus(201);

        $this->assertTrue((bool) Product::sole()->is_active);
    }

    /**
     * Nothing should go live because a caller forgot to mention it. The form
     * always sends the field now, so the only callers this reaches are ones
     * that omit it — and for those, staying off the storefront is the safe
     * direction.
     */
    public function test_a_product_created_without_saying_defaults_to_a_draft(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload())
            ->assertStatus(201);

        $this->assertFalse((bool) Product::sole()->is_active);
    }

    /** A draft is not a broken product: publishing it later is an ordinary edit. */
    public function test_a_draft_can_be_published_afterwards(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/products', $this->payload(['is_active' => false]))
            ->assertStatus(201);

        $product = Product::sole();

        $this->actingAs($admin)
            ->patchJson("/api/admin/products/{$product->id}", ['is_active' => true])
            ->assertOk();

        $this->assertTrue((bool) $product->fresh()->is_active);
    }

    /** And a live product can be taken back down the same way. */
    public function test_a_published_product_can_be_unpublished(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/products', $this->payload(['is_active' => true]))
            ->assertStatus(201);

        $product = Product::sole();

        $this->actingAs($admin)
            ->patchJson("/api/admin/products/{$product->id}", ['is_active' => false])
            ->assertOk();

        $this->assertFalse((bool) $product->fresh()->is_active);
    }

    /**
     * A draft is withheld from shoppers, not merely hidden from a listing.
     *
     * Asked of the API rather than the page: /products/{slug} is an Inertia
     * shell that renders for any slug and fetches the product itself, so it
     * answers 200 for a product that does not exist at all. The gate is here.
     */
    public function test_a_draft_is_not_served_to_shoppers(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload(['is_active' => false]))
            ->assertStatus(201);

        $slug = Product::sole()->slug;

        $this->getJson("/api/products/{$slug}")->assertNotFound();

        // And is served the moment it is published, so the draft is the only
        // thing keeping it back.
        Product::sole()->update(['is_active' => true]);

        $this->getJson("/api/products/{$slug}")->assertOk();
    }

    /** Nor listed, which is the half a shopper browsing would notice. */
    public function test_a_draft_is_not_in_the_listing(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload(['is_active' => false]))
            ->assertStatus(201);

        $this->assertSame(
            [],
            $this->getJson('/api/products')->json('data.data') ?? [],
        );
    }
}
