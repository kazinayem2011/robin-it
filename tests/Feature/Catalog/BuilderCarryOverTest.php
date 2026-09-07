<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The homepage's three-part widget hands its picks to the full builder as
 * ?cpu=&gpu=&ram=, and the builder looks each product up inside the matching
 * slot. That only works while the two agree about what those slots are.
 *
 * They have disagreed twice. First the widget asked the catalogue for 'cpu',
 * 'gpu' and 'ram' — the old taxonomy's names — and rendered three empty
 * pickers. Then the carry-over asked the builder for the same three, found no
 * such slot, and silently carried nothing: choosing a processor, a card and
 * memory on the front page and pressing "Finalize this rig" landed you on an
 * empty builder either way.
 *
 * Both times the failure was silent and looked like the shopper had simply
 * picked nothing. This asserts the agreement rather than the spelling, so a
 * shop on either taxonomy is covered and a rename is caught here.
 */
class BuilderCarryOverTest extends TestCase
{
    use RefreshDatabase;

    /** What the builder page will accept for each widget parameter. */
    private const CANDIDATES = [
        'cpu' => ['component-processor', 'cpu'],
        'gpu' => ['component-graphics-card', 'graphics-card', 'gpu'],
        'ram' => ['component-ram-desktop', 'ram', 'memory'],
    ];

    private function seedShelf(string $slug, string $productName): int
    {
        $shelf = Category::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'is_active' => true,
        ]);

        return Product::create([
            'category_id' => $shelf->id,
            'name' => $productName,
            'slug' => Str::slug($productName),
            'price' => 5000,
            'stock_quantity' => 4,
            'is_active' => true,
        ])->id;
    }

    public function test_every_widget_group_has_a_slot_the_builder_serves(): void
    {
        $this->seedShelf('component-processor', 'A Processor');
        $this->seedShelf('component-graphics-card', 'A Graphics Card');
        $this->seedShelf('component-ram-desktop', 'Some Memory');

        $slotIds = collect(app(ProductService::class)->getPcBuilderCategories())
            ->pluck('id')
            ->all();

        foreach (self::CANDIDATES as $param => $candidates) {
            $this->assertNotEmpty(
                array_intersect($candidates, $slotIds),
                "the builder serves no slot the homepage's \"{$param}\" pick could be carried into"
            );
        }
    }

    /**
     * The whole point: a product the widget offers has to be findable inside
     * the slot the carry-over will look in. This is the assertion that fails
     * when the two drift apart.
     */
    public function test_a_widget_pick_is_found_in_the_slot_it_carries_into(): void
    {
        $this->seedShelf('component-processor', 'A Processor');
        $this->seedShelf('component-graphics-card', 'A Graphics Card');
        $this->seedShelf('component-ram-desktop', 'Some Memory');

        $service = app(ProductService::class);
        $specs = $service->getBuilderQuickSpecs();
        $slotIds = collect($service->getPcBuilderCategories())->pluck('id')->all();

        foreach (self::CANDIDATES as $param => $candidates) {
            $offered = $specs[$param] ?? [];
            $this->assertNotEmpty($offered, "the homepage widget offers nothing for \"{$param}\"");

            $pick = (array) reset($offered);
            $slotId = (string) collect($candidates)->first(fn ($c) => in_array($c, $slotIds, true));

            $inSlot = collect($service->getPcBuilderComponents($slotId))
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $this->assertContains(
                (string) $pick['id'],
                $inSlot,
                "a \"{$param}\" the homepage offers cannot be found in the \"{$slotId}\" slot it is carried into"
            );
        }
    }
}
