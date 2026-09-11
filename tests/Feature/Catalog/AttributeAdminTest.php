<?php

namespace Tests\Feature\Catalog;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managing the questions the filter sidebar asks.
 *
 * There was no screen for this, so a new category could be given products,
 * photos and a full spec sheet and still be unfilterable — the 66 attributes
 * existed because a seeder made them and nothing could add a sixty-seventh.
 *
 * The delicate part is not creating one. It is that an answer and a product's
 * tick are joined by a cascading foreign key, so deleting carelessly untags
 * products without a word.
 */
class AttributeAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function shelf(string $name = 'Router'): Category
    {
        return Category::firstOrCreate(
            ['slug' => str($name)->slug()->value()],
            ['name' => $name, 'is_active' => true],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @return array<string, mixed>
     */
    private function payload(array $overrides = [], array $values = []): array
    {
        return array_merge([
            'name' => 'Wi-Fi Standard',
            'input_type' => Attribute::ENUM,
            'values' => $values ?: [
                ['label' => 'Wi-Fi 5'],
                ['label' => 'Wi-Fi 6'],
            ],
        ], $overrides);
    }

    private function tag(Product $product, AttributeValue $value): void
    {
        $product->attributeValues()->attach($value->id);
    }

    private function product(Category $shelf): Product
    {
        return Product::create([
            'category_id' => $shelf->id,
            'name' => 'Archer AX55 '.uniqid(),
            'slug' => 'archer-'.uniqid(),
            'price' => 5400,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);
    }

    // --- who may reach it --------------------------------------------------

    public function test_the_screen_needs_the_catalogue_ability(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::SUPPORT]))
            ->get('/admin/attributes')
            ->assertRedirect('/admin/dashboard');

        $this->actingAs($this->admin())
            ->get('/admin/attributes')
            ->assertOk();
    }

    public function test_a_customer_cannot_create_a_filter(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/api/admin/attributes', $this->payload())
            ->assertForbidden();
    }

    // --- creating ----------------------------------------------------------

    public function test_an_admin_can_create_a_filter_with_its_answers(): void
    {
        $shelf = $this->shelf();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/attributes', $this->payload([
                'category_ids' => [$shelf->id],
            ]))
            ->assertStatus(201);

        $attribute = Attribute::sole();

        $this->assertSame('Wi-Fi Standard', $attribute->name);
        $this->assertSame('wi-fi-standard', $attribute->slug);
        $this->assertSame(['Wi-Fi 5', 'Wi-Fi 6'], $attribute->values->pluck('label')->all());
        $this->assertSame([$shelf->id], $attribute->categories->pluck('id')->all());
    }

    /**
     * Six shelves ask "Features" and four ask "Type", each about its own kind
     * of product. The name is not the identifier; the slug is.
     */
    public function test_two_shelves_may_ask_a_question_with_the_same_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/attributes', $this->payload(['name' => 'Features']))
            ->assertStatus(201);
        $this->actingAs($admin)->postJson('/api/admin/attributes', $this->payload(['name' => 'Features']))
            ->assertStatus(201);

        $this->assertSame(
            ['features', 'features-2'],
            Attribute::orderBy('id')->pluck('slug')->all(),
        );
    }

    /** A filter with nothing to tick is not a filter. */
    public function test_a_filter_needs_at_least_one_answer(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/attributes', $this->payload(['values' => []]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['values']]]);
    }

    /** One checkbox drawn twice hides half the matching products behind the other. */
    public function test_two_answers_cannot_share_a_label(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/attributes', $this->payload([], [
                ['label' => 'Dual Band'],
                ['label' => 'dual band'],
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['values.1.label']]]);
    }

    // --- number bands ------------------------------------------------------

    public function test_a_number_filter_keeps_the_bounds_of_each_band(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/attributes', $this->payload([
                'name' => 'Speed',
                'input_type' => Attribute::NUMBER,
                'unit' => 'Mbps',
            ], [
                ['label' => 'Up to 300 Mbps', 'range_to' => 300],
                ['label' => '301 to 750 Mbps', 'range_from' => 301, 'range_to' => 750],
                ['label' => '1801 Mbps and above', 'range_from' => 1801],
            ]))
            ->assertStatus(201);

        $attribute = Attribute::with('values')->sole();

        $this->assertSame('Mbps', $attribute->unit);
        $this->assertSame('301 to 750 Mbps', $attribute->bandFor(500)->label);
        $this->assertSame('Up to 300 Mbps', $attribute->bandFor(120)->label);
        $this->assertSame('1801 Mbps and above', $attribute->bandFor(2400)->label);
    }

    public function test_a_band_with_no_bounds_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/attributes', $this->payload([
                'input_type' => Attribute::NUMBER,
            ], [
                ['label' => 'Fast'],
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['values.0.range_from']]]);
    }

    public function test_a_band_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/attributes', $this->payload([
                'input_type' => Attribute::NUMBER,
            ], [
                ['label' => 'Backwards', 'range_from' => 900, 'range_to' => 300],
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['values.0.range_to']]]);
    }

    /** A bound on a list of names means nothing, and would be drawn as a range. */
    public function test_a_list_of_names_cannot_carry_bounds(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/attributes', $this->payload([], [
                ['label' => 'IPS', 'range_from' => 1, 'range_to' => 9],
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['values.0.range_from']]]);
    }

    /** A stale "Mbps" on a panel-type question is waiting to be rendered somewhere. */
    public function test_switching_away_from_a_number_clears_the_unit(): void
    {
        $attribute = Attribute::create([
            'name' => 'Speed', 'slug' => 'speed',
            'unit' => 'Mbps', 'input_type' => Attribute::NUMBER,
        ]);
        $attribute->values()->create(['label' => 'Fast', 'slug' => 'fast', 'range_from' => 1]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/attributes/{$attribute->id}", $this->payload([
                'name' => 'Panel Type',
                'input_type' => Attribute::ENUM,
                'unit' => 'Mbps',
            ], [
                ['label' => 'IPS'],
            ]))
            ->assertOk();

        $this->assertNull($attribute->fresh()->unit);
    }

    // --- editing without losing a product's tick ---------------------------

    /** A tick survives the wording beside it being tidied up. */
    public function test_renaming_an_answer_keeps_the_products_tagged_with_it(): void
    {
        $shelf = $this->shelf();
        $attribute = Attribute::create(['name' => 'Band', 'slug' => 'band', 'input_type' => Attribute::ENUM]);
        $value = $attribute->values()->create(['label' => 'Dual Band', 'slug' => 'dual-band']);

        $product = $this->product($shelf);
        $this->tag($product, $value);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/attributes/{$attribute->id}", $this->payload([
                'name' => 'Band',
            ], [
                ['id' => $value->id, 'label' => 'Dual-Band (2.4 + 5 GHz)'],
            ]))
            ->assertOk();

        $this->assertSame('Dual-Band (2.4 + 5 GHz)', $value->fresh()->label);
        $this->assertSame([$value->id], $product->fresh()->attributeValues->pluck('id')->all());
    }

    /**
     * The join cascades, so dropping an answer in use would untag every product
     * carrying it and say nothing. Refused instead, with the count.
     */
    public function test_an_answer_in_use_cannot_be_dropped_from_the_list(): void
    {
        $shelf = $this->shelf();
        $attribute = Attribute::create(['name' => 'Band', 'slug' => 'band', 'input_type' => Attribute::ENUM]);
        $keep = $attribute->values()->create(['label' => 'Single Band', 'slug' => 'single-band']);
        $used = $attribute->values()->create(['label' => 'Dual Band', 'slug' => 'dual-band']);

        $product = $this->product($shelf);
        $this->tag($product, $used);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/attributes/{$attribute->id}", $this->payload([
                'name' => 'Band',
            ], [
                ['id' => $keep->id, 'label' => 'Single Band'],
            ]))
            ->assertStatus(422);

        $this->assertDatabaseHas('attribute_values', ['id' => $used->id]);
        $this->assertSame([$used->id], $product->fresh()->attributeValues->pluck('id')->all());
    }

    /** An answer nobody uses is just a mistake being corrected. */
    public function test_an_unused_answer_can_be_dropped(): void
    {
        $attribute = Attribute::create(['name' => 'Band', 'slug' => 'band', 'input_type' => Attribute::ENUM]);
        $keep = $attribute->values()->create(['label' => 'Single Band', 'slug' => 'single-band']);
        $typo = $attribute->values()->create(['label' => 'Duel Band', 'slug' => 'duel-band']);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/attributes/{$attribute->id}", $this->payload([
                'name' => 'Band',
            ], [
                ['id' => $keep->id, 'label' => 'Single Band'],
            ]))
            ->assertOk();

        $this->assertDatabaseMissing('attribute_values', ['id' => $typo->id]);
    }

    // --- deleting ----------------------------------------------------------

    public function test_a_filter_in_use_cannot_be_deleted(): void
    {
        $shelf = $this->shelf();
        $attribute = Attribute::create(['name' => 'Band', 'slug' => 'band', 'input_type' => Attribute::ENUM]);
        $value = $attribute->values()->create(['label' => 'Dual Band', 'slug' => 'dual-band']);

        $product = $this->product($shelf);
        $this->tag($product, $value);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/attributes/{$attribute->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('attributes', ['id' => $attribute->id]);
        $this->assertSame(1, $product->fresh()->attributeValues->count());
    }

    public function test_an_unused_filter_can_be_deleted_outright(): void
    {
        $attribute = Attribute::create(['name' => 'Band', 'slug' => 'band', 'input_type' => Attribute::ENUM]);
        $value = $attribute->values()->create(['label' => 'Dual Band', 'slug' => 'dual-band']);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/attributes/{$attribute->id}")
            ->assertOk();

        $this->assertDatabaseMissing('attributes', ['id' => $attribute->id]);
        $this->assertDatabaseMissing('attribute_values', ['id' => $value->id]);
    }

    // --- asking the same question elsewhere --------------------------------

    /**
     * Twenty-nine of the sixty-six filters in this shop are already a repeat
     * of another by name — Features is asked by six shelves, Type by four — so
     * re-creating a question for a different shelf is nearly half of them.
     * Processor Model carries sixteen answers, and retyping those to ask the
     * same thing about desktops is the work this removes.
     */
    public function test_copying_brings_every_answer_with_it(): void
    {
        $attribute = Attribute::create([
            'name' => 'Display Type', 'slug' => 'display-type', 'input_type' => Attribute::ENUM,
        ]);
        $attribute->values()->create(['label' => 'LED', 'slug' => 'led', 'sort_order' => 0]);
        $attribute->values()->create(['label' => 'OLED', 'slug' => 'oled', 'sort_order' => 1]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/attributes/{$attribute->id}/duplicate")
            ->assertStatus(201);

        $copy = Attribute::with('values')->latest('id')->first();

        $this->assertSame(['LED', 'OLED'], $copy->values->pluck('label')->all());
        $this->assertSame(Attribute::ENUM, $copy->input_type);
    }

    /**
     * Not suffixed, for the same reason the name is not unique: asking
     * "Display Type" about monitors as well as laptops is the intended shape,
     * and a copy called "Display Type (Copy)" would be renamed back every
     * time. The slug takes the suffix, where nobody has to read it.
     */
    public function test_the_copy_keeps_the_question_and_takes_a_new_address(): void
    {
        $attribute = Attribute::create([
            'name' => 'Display Type', 'slug' => 'display-type', 'input_type' => Attribute::ENUM,
        ]);
        $attribute->values()->create(['label' => 'LED', 'slug' => 'led']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/attributes/{$attribute->id}/duplicate")
            ->assertStatus(201);

        $copy = Attribute::latest('id')->first();

        $this->assertSame('Display Type', $copy->name);
        $this->assertSame('display-type-2', $copy->slug);
    }

    /**
     * The shelves are the one thing that differs between the two, and an
     * unattached filter is marked as such on the list — so what arrives is
     * visibly the thing still needing a decision, rather than a second filter
     * quietly answering for the shelf the first already covers.
     */
    public function test_the_copy_is_attached_to_nothing(): void
    {
        $shelf = $this->shelf();
        $attribute = Attribute::create([
            'name' => 'Display Type', 'slug' => 'display-type', 'input_type' => Attribute::ENUM,
        ]);
        $attribute->values()->create(['label' => 'LED', 'slug' => 'led']);
        $attribute->categories()->sync([$shelf->id]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/attributes/{$attribute->id}/duplicate")
            ->assertStatus(201);

        $this->assertSame([], Attribute::latest('id')->first()->categories->pluck('id')->all());
        $this->assertSame([$shelf->id], $attribute->fresh()->categories->pluck('id')->all());
    }

    /** A band carries its bounds, or the copy places nothing. */
    public function test_a_number_filter_copies_its_bands(): void
    {
        $attribute = Attribute::create([
            'name' => 'Speed', 'slug' => 'speed',
            'unit' => 'Mbps', 'input_type' => Attribute::NUMBER,
        ]);
        $attribute->values()->create([
            'label' => '301 to 750 Mbps', 'slug' => '301-750',
            'range_from' => 301, 'range_to' => 750,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/attributes/{$attribute->id}/duplicate")
            ->assertStatus(201);

        $copy = Attribute::with('values')->latest('id')->first();

        $this->assertSame('Mbps', $copy->unit);
        $this->assertSame('301 to 750 Mbps', $copy->bandFor(500)->label);
    }

    /** Nobody answers a filter that did not exist a moment ago. */
    public function test_the_copy_carries_no_products(): void
    {
        $shelf = $this->shelf();
        $attribute = Attribute::create([
            'name' => 'Band', 'slug' => 'band', 'input_type' => Attribute::ENUM,
        ]);
        $value = $attribute->values()->create(['label' => 'Dual Band', 'slug' => 'dual-band']);
        $this->tag($this->product($shelf), $value);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/attributes/{$attribute->id}/duplicate")
            ->assertStatus(201);

        $copy = Attribute::with('values')->latest('id')->first();

        $this->assertSame(0, $copy->values->first()->products()->count());
        $this->assertSame(1, $value->products()->count(), 'The original lost its tags.');
    }

    public function test_copying_needs_the_catalogue_ability(): void
    {
        $attribute = Attribute::create([
            'name' => 'Band', 'slug' => 'band', 'input_type' => Attribute::ENUM,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson("/api/admin/attributes/{$attribute->id}/duplicate")
            ->assertForbidden();

        $this->assertSame(1, Attribute::count());
    }

    // --- reaching the product form ----------------------------------------

    /**
     * The whole point of the screen: a filter created here has to show up on
     * the product form for that shelf, with no code in between.
     */
    public function test_a_new_filter_reaches_the_product_form_for_its_shelf(): void
    {
        $shelf = $this->shelf('Router');
        $leaf = Category::create([
            'name' => 'TP-Link', 'slug' => 'tp-link-router',
            'parent_id' => $shelf->id, 'is_active' => true,
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/attributes', $this->payload(['category_ids' => [$shelf->id]]))
            ->assertStatus(201);

        // Asked of the leaf, because attributes are inherited downward.
        $offered = $this->actingAs($admin)
            ->getJson("/api/admin/categories/{$leaf->id}/attributes")
            ->assertOk()
            ->json('data');

        $this->assertSame('Wi-Fi Standard', $offered[0]['name']);
        $this->assertSame(
            ['Wi-Fi 5', 'Wi-Fi 6'],
            array_column($offered[0]['values'], 'label'),
        );
    }

    /** Unlinking is how a filter in use is retired: it stops being offered. */
    public function test_unlinking_a_shelf_stops_it_being_offered_there(): void
    {
        $shelf = $this->shelf();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/attributes', $this->payload(['category_ids' => [$shelf->id]]))
            ->assertStatus(201);

        $attribute = Attribute::sole();

        $this->actingAs($admin)
            ->patchJson("/api/admin/attributes/{$attribute->id}", $this->payload(['category_ids' => []]))
            ->assertOk();

        $this->assertSame(
            [],
            $this->actingAs($admin)
                ->getJson("/api/admin/categories/{$shelf->id}/attributes")
                ->json('data'),
        );
    }
}
