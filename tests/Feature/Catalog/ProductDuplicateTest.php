<?php

namespace Tests\Feature\Catalog;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Starting a new product from one that already exists.
 *
 * Entering one from scratch is six panels of work, and most of a shop's range
 * is near-identical to something already on it: same shelf, same warranty
 * wording, same filter answers, different processor. Retyping all of it to
 * change one line is the slowest part of the form and the most error-prone —
 * the filter answers especially, and a product answering none of them is
 * invisible to the sidebar.
 *
 * What must not be copied is anything identifying the goods rather than
 * describing them: the slug, the barcode, the stock, and whether it is live.
 */
class ProductDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function shelf(string $slug = 'laptop', string $name = 'Laptop'): Category
    {
        return Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
    }

    private function source(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->shelf()->id,
            'name' => 'ASUS TUF Gaming A15',
            'slug' => 'asus-tuf-gaming-a15',
            'barcode' => '4711387475836',
            'price' => 145000,
            'discount_price' => 138500,
            'short_description' => 'A fast machine.',
            'description' => '<p>Long copy.</p>',
            'warranty_months' => 24,
            'warranty_text' => '2 Years (Battery 1 Year)',
            'model' => 'FA507NU',
            'mpn' => 'FA507NU-LP031W',
            'stock_quantity' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function copy(Product $source): Product
    {
        $this->actingAs($this->admin())
            ->postJson("/api/admin/products/{$source->id}/duplicate")
            ->assertStatus(201);

        return Product::latest('id')->firstOrFail();
    }

    // --- what comes across -------------------------------------------------

    public function test_it_copies_what_describes_the_product(): void
    {
        $copy = $this->copy($this->source());

        $this->assertSame(145000.0, (float) $copy->price);
        $this->assertSame(138500.0, (float) $copy->discount_price);
        $this->assertSame('A fast machine.', $copy->short_description);
        $this->assertSame('2 Years (Battery 1 Year)', $copy->warranty_text);
        $this->assertSame('FA507NU', $copy->model);
        $this->assertSame('FA507NU-LP031W', $copy->mpn);
    }

    /** The part worth copying most: without it the copy is unfilterable. */
    public function test_it_copies_the_filter_answers(): void
    {
        $attribute = Attribute::create([
            'name' => 'Display Type', 'slug' => 'display-type', 'input_type' => 'enum',
        ]);
        $led = $attribute->values()->create(['label' => 'LED', 'slug' => 'led']);

        $source = $this->source();
        $source->attributeValues()->attach($led->id);

        $this->assertSame([$led->id], $this->copy($source)->attributeValues->pluck('id')->all());
    }

    public function test_it_copies_the_spec_sheet(): void
    {
        $source = $this->source();
        $source->specifications()->create([
            'group' => 'Processor', 'name' => 'Model', 'value' => 'Ryzen 7 7735HS',
        ]);

        $copy = $this->copy($source);

        $this->assertSame(1, $copy->specifications()->count());
        $this->assertSame('Ryzen 7 7735HS', $copy->specifications()->first()->value);
    }

    public function test_it_copies_every_shelf_it_is_listed_on(): void
    {
        $extra = $this->shelf('gaming-laptop', 'Gaming Laptop');
        $source = $this->source();
        $source->syncCategories([$extra->id]);

        $this->assertEqualsCanonicalizing(
            $source->categories->pluck('id')->all(),
            $this->copy($source)->categories->pluck('id')->all(),
        );
    }

    public function test_it_copies_the_photos(): void
    {
        $source = $this->source();
        $source->images()->create(['image_path' => 'products/a15.jpg', 'is_primary' => true]);

        $this->assertSame('products/a15.jpg', $this->copy($source)->images->first()->image_path);
    }

    // --- what must not ------------------------------------------------------

    /** Unique by definition: it is the number on this shop's own box. */
    public function test_the_barcode_is_left_for_the_new_box(): void
    {
        $source = $this->source();
        $copy = $this->copy($source);

        $this->assertNull($copy->barcode, 'The copy claimed the original\'s barcode.');
        $this->assertSame(
            '4711387475836',
            $source->fresh()->barcode,
            'The original lost its barcode to the copy.',
        );
    }

    public function test_it_gets_an_address_of_its_own(): void
    {
        $source = $this->source();
        $copy = $this->copy($source);

        $this->assertNotSame($source->slug, $copy->slug);
    }

    /** A copy has no stock. Stock arrives under Purchasing and nowhere else. */
    public function test_it_arrives_with_an_empty_shelf(): void
    {
        $source = $this->source();
        app(StockService::class)->receive([], [[
            'product_id' => $source->id, 'quantity' => 9,
        ]]);

        $copy = $this->copy($source->fresh());

        $this->assertSame(0, (int) $copy->stock_quantity);
        $this->assertSame(9, (int) $source->fresh()->stock_quantity, 'The original lost stock.');
    }

    /** A copy is never finished on arrival, so nothing reaches shoppers by accident. */
    public function test_it_is_a_draft(): void
    {
        $this->assertFalse((bool) $this->copy($this->source())->is_active);
    }

    // --- telling the two apart ---------------------------------------------

    public function test_the_name_says_it_is_a_copy(): void
    {
        $this->assertSame('ASUS TUF Gaming A15 (Copy)', $this->copy($this->source())->name);
    }

    /**
     * Numbered rather than "(Copy) (Copy)", which is what a shop working
     * through a range of eight would otherwise end up reading.
     */
    public function test_copying_a_copy_counts_rather_than_stacking(): void
    {
        $source = $this->source();

        $first = $this->copy($source);
        $second = $this->copy($first);

        $this->assertSame('ASUS TUF Gaming A15 (Copy)', $first->name);
        $this->assertSame('ASUS TUF Gaming A15 (Copy 2)', $second->name);
    }

    // --- who may -----------------------------------------------------------

    public function test_it_needs_the_catalogue_ability(): void
    {
        $source = $this->source();

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson("/api/admin/products/{$source->id}/duplicate")
            ->assertForbidden();

        $this->assertSame(1, Product::count());
    }
}
