<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The PC builder's blueprint, against a catalogue that can be reorganised.
 *
 * Each slot used to name one hard-coded slug — `cpu`, `motherboard`,
 * `pc-case`, `mice` — from the tree the shop had when it was written.
 * Reorganising the catalogue renamed every one of them, and since a missing
 * category is simply skipped, the builder returned an empty blueprint with no
 * error in any log. A feature that silently becomes nothing is worse than one
 * that breaks loudly.
 */
class PcBuilderBlueprintTest extends TestCase
{
    use RefreshDatabase;

    private function shelf(string $name, string $slug): Category
    {
        return Category::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
    }

    private function stock(Category $category, string $name): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => 20000,
            'stock_quantity' => 4,
            'is_active' => true,
        ]);
    }

    private function blueprint(): array
    {
        return app(ProductService::class)->getPcBuilderCategories();
    }

    /** The tree the shop runs today. */
    public function test_it_finds_the_current_catalogue(): void
    {
        $cpu = $this->shelf('Processor', 'component-processor');
        $this->stock($cpu, 'Core i5');

        $slot = collect($this->blueprint())->firstWhere('name', 'Processor');

        $this->assertNotNull($slot, 'The processor slot was not offered.');
        $this->assertSame('component-processor', $slot['category_slug']);
        $this->assertSame(1, $slot['available']);
    }

    /** And the one it used to run, so a rename is survivable either way. */
    public function test_it_still_finds_the_previous_catalogue(): void
    {
        $cpu = $this->shelf('CPU', 'cpu');
        $this->stock($cpu, 'Core i5');

        $slot = collect($this->blueprint())->firstWhere('category_slug', 'cpu');

        $this->assertNotNull($slot, 'The old slug stopped resolving.');
        $this->assertSame(1, $slot['available']);
    }

    /** The current name wins where a catalogue somehow carries both. */
    public function test_the_current_name_is_preferred(): void
    {
        $this->stock($this->shelf('Processor', 'component-processor'), 'New');
        $this->stock($this->shelf('CPU', 'cpu'), 'Old');

        $slugs = collect($this->blueprint())->pluck('category_slug');

        $this->assertContains('component-processor', $slugs);
        $this->assertNotContains('cpu', $slugs);
    }

    /** A slot with no shelf anywhere is left out, not offered empty. */
    public function test_a_slot_with_no_category_is_not_offered(): void
    {
        $this->stock($this->shelf('Processor', 'component-processor'), 'Core i5');

        $names = collect($this->blueprint())->pluck('name');

        $this->assertContains('Processor', $names);
        $this->assertNotContains('Motherboard', $names);
    }

    /**
     * The regression itself: a catalogue that answers none of the old names
     * must still produce a blueprint, not silence.
     */
    public function test_the_new_catalogue_alone_still_produces_a_builder(): void
    {
        foreach ([
            'Processor' => 'component-processor',
            'Motherboard' => 'component-motherboard',
            'RAM Desktop' => 'component-ram-desktop',
            'SSD' => 'component-ssd',
            'Power Supply' => 'component-power-supply',
            'Casing' => 'component-casing',
        ] as $name => $slug) {
            $this->stock($this->shelf($name, $slug), $name.' part');
        }

        $blueprint = $this->blueprint();

        $this->assertCount(6, $blueprint);
        $this->assertSame(
            [],
            collect($blueprint)->where('available', 0)->pluck('name')->all(),
            'A slot was offered with nothing to choose from.'
        );
    }
}
