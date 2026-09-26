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

    /**
     * What a card lists when the spec sheet has not been filled in yet.
     *
     * The summary was handed over whole, so a card carrying a real one drew a
     * single bullet running the width of the tile — "Core i5 · 16GB · 512GB ·
     * RTX 3050" as one item — while every seeded product beside it, which does
     * have specifications, showed several.
     */
    public function test_a_summary_is_listed_as_separate_points(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'is_active' => true,
                'short_description' => 'Intel Core i5-13420H · 16GB DDR5 · 512GB NVMe SSD · RTX 3050 · 144Hz',
            ]))
            ->assertStatus(201);

        $card = $this->getJson('/api/products')->json('data.0');

        $this->assertSame([
            'Intel Core i5-13420H',
            '16GB DDR5',
            '512GB NVMe SSD',
            'RTX 3050',
        ], $card['specs'], 'Capped at four, as StarTech\'s card is.');
    }

    /**
     * The card is the product page's Key Features without the model line, as
     * on StarTech: processor, memory and storage, display, features.
     */
    public function test_a_card_lists_the_key_features_without_the_model(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'is_active' => true,
                'key_features' => '<ul><li>Model: 15-fc0626AU</li>'
                    .'<li>Processor: AMD Ryzen 3 7320U (4MB L3 Cache, Up to 4.1 GHz)</li>'
                    .'<li>RAM: 8GB LPDDR5, Storage: 512GB NVMe SSD</li>'
                    .'<li>Display: 15.6&quot; FHD (1920 x 1080)</li>'
                    .'<li>Features: Backlit Keyboard, Type-C</li>'
                    .'<li>Warranty: 2 years</li></ul>',
                'specifications' => [
                    ['group' => 'Processor', 'name' => 'Processor Brand', 'value' => 'AMD'],
                ],
            ]))
            ->assertStatus(201);

        $this->assertSame([
            'Processor: AMD Ryzen 3 7320U (4MB L3 Cache, Up to 4.1 GHz)',
            'RAM: 8GB LPDDR5, Storage: 512GB NVMe SSD',
            'Display: 15.6" FHD (1920 x 1080)',
            'Features: Backlit Keyboard, Type-C',
        ], $this->getJson('/api/products')->json('data.0.specs'));
    }

    /**
     * Any category, not only laptops: memory on StarTech opens with the part
     * number and then the model, and its card leaves both out.
     */
    public function test_a_card_leaves_out_the_part_number_as_well(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'is_active' => true,
                'key_features' => '<ul><li>MPN: F4-2666C19S-8GNT</li><li>Model: Value</li>'
                    .'<li>Capacity: 8GB</li><li>Speed: 2666 MHz</li>'
                    .'<li>Latency: 19-19-19-43</li><li>Voltage: 1.20V</li></ul>',
            ]))
            ->assertStatus(201);

        $this->assertSame(
            ['Capacity: 8GB', 'Speed: 2666 MHz', 'Latency: 19-19-19-43', 'Voltage: 1.20V'],
            $this->getJson('/api/products')->json('data.0.specs'),
        );
    }

    /** Pasted as paragraphs or line breaks rather than a list, it reads the same. */
    public function test_key_features_written_as_lines_are_read_as_lines(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'is_active' => true,
                'key_features' => '<p>Model: K8 Pro</p><p>Switch: Gateron Red<br>Layout: 75%</p>',
            ]))
            ->assertStatus(201);

        $this->assertSame(
            ['Switch: Gateron Red', 'Layout: 75%'],
            $this->getJson('/api/products')->json('data.0.specs'),
        );
    }

    /** Newlines too, which is what the box now invites. */
    public function test_a_summary_written_on_separate_lines_is_split_the_same_way(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'is_active' => true,
                'short_description' => "8 cores, 16 threads\nRTX 3050 4GB\n144Hz display",
            ]))
            ->assertStatus(201);

        $this->assertSame(
            ['8 cores, 16 threads', 'RTX 3050 4GB', '144Hz display'],
            $this->getJson('/api/products')->json('data.0.specs'),
        );
    }

    /** A real spec sheet still wins; the summary is only the fallback. */
    public function test_specifications_are_preferred_over_the_summary(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload([
                'is_active' => true,
                'short_description' => 'Ignored · Because · Specs exist',
                'specifications' => [
                    ['group' => 'Processor', 'name' => 'Model', 'value' => 'Core i5'],
                ],
            ]))
            ->assertStatus(201);

        $this->assertSame(
            ['Model: Core i5'],
            $this->getJson('/api/products')->json('data.0.specs'),
        );
    }

    /** Nor listed, which is the half a shopper browsing would notice. */
    public function test_a_draft_is_not_in_the_listing(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/products', $this->payload(['is_active' => false]))
            ->assertStatus(201);

        $this->assertSame(
            [],
            $this->getJson('/api/products')->json('data') ?? [],
        );
    }
}
