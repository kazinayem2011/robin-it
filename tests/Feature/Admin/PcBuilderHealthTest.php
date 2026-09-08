<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\User;
use App\Services\PcCompatibilityService;
use App\Support\PcBuilderHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin had no way to see why a part is or is not in the builder.
 *
 * A product reaches a slot through the category it is filed under and its
 * Active tick — not its stock, and not any switch on the builder. Compatibility
 * is checked from specifications whose names have to match, and a missing one
 * counts as "unknown" rather than a failure, so a build nobody could check
 * looked exactly like one that passed. None of that was visible anywhere.
 */
class PcBuilderHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A shelf shaped like the real ones: "Processor" sitting under
     * "Component", rather than a category named after its own slug. The path
     * this produces is what the product form's picker shows, and what the
     * screen has to print for the two to be matched by eye.
     */
    private function shelf(string $slug): Category
    {
        $parent = null;

        if (str_starts_with($slug, 'component-')) {
            $parent = Category::firstOrCreate(
                ['slug' => 'component'],
                ['name' => 'Component', 'is_active' => true]
            );
        }

        $name = ucwords(str_replace('-', ' ', preg_replace('/^component-/', '', $slug)));

        return Category::create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'parent_id' => $parent?->id,
        ]);
    }

    private function part(Category $shelf, string $name, array $specs = [], int $stock = 4, bool $active = true): Product
    {
        $product = Product::create([
            'category_id' => $shelf->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => 5000,
            'stock_quantity' => $stock,
            'is_active' => $active,
        ]);

        foreach ($specs as $key => $value) {
            ProductSpecification::create([
                'product_id' => $product->id,
                'name' => $key,
                'value' => $value,
            ]);
        }

        return $product;
    }

    private function slot(string $id): ?array
    {
        return collect(app(PcBuilderHealth::class)->slots())->firstWhere('id', $id);
    }

    public function test_it_names_the_category_a_slot_is_filled_from(): void
    {
        $this->part($this->shelf('component-processor'), 'A Chip');

        $slot = $this->slot('component-processor');

        /*
         * The path the product form's category picker shows, not the slug.
         * This reported "component-processor", which is a developer's name for
         * the shelf and appears nowhere an admin can see — so the guide told
         * them to match it against a picker listing "Component › Processor".
         */
        $this->assertSame('component-processor', $slot['category_slug']);
        $this->assertSame('Component › Processor', $slot['category']);
        $this->assertSame(1, $slot['parts']);
        $this->assertFalse($slot['starved']);
    }

    /** The rule nobody could discover: Active is the filter, stock is not. */
    public function test_an_inactive_part_is_not_counted_but_an_out_of_stock_one_is(): void
    {
        $shelf = $this->shelf('component-processor');
        $this->part($shelf, 'On Sale', [], stock: 5);
        $this->part($shelf, 'Sold Out', [], stock: 0);
        $this->part($shelf, 'Hidden', [], stock: 5, active: false);

        $slot = $this->slot('component-processor');

        $this->assertSame(2, $slot['parts'], 'the inactive part should not be offered');
        $this->assertSame(1, $slot['in_stock']);
        $this->assertSame(1, $slot['out_of_stock'], 'an out-of-stock part is still offered');
    }

    public function test_it_reports_which_specs_a_slot_needs(): void
    {
        $this->part($this->shelf('component-processor'), 'A Chip');

        $this->assertSame(['Socket', 'TDP'], $this->slot('component-processor')['needs_specs']);
    }

    /**
     * The count that matters: a part without these is carried as "unknown", so
     * the build is never actually checked.
     */
    public function test_it_counts_parts_that_cannot_be_compatibility_checked(): void
    {
        $shelf = $this->shelf('component-processor');
        $this->part($shelf, 'Fully Specified', ['Socket' => 'AM5', 'TDP' => '105W']);
        $this->part($shelf, 'No Specs At All');
        $this->part($shelf, 'Half Specified', ['Socket' => 'AM5']);

        $this->assertSame(2, $this->slot('component-processor')['missing_specs']);
    }

    /**
     * A required slot with nothing in it is kept and flagged, because hiding it
     * would make a build look completable when it is not. An optional one, or
     * one whose category is gone, is dropped by the builder before this sees it.
     */
    public function test_it_flags_a_required_slot_with_nothing_in_it(): void
    {
        // The shelf exists so the builder offers the slot; nothing is on it.
        $this->shelf('component-processor');

        $slot = $this->slot('component-processor');

        $this->assertTrue($slot['starved']);
        $this->assertSame(0, $slot['parts']);
    }

    public function test_the_summary_totals_what_the_dashboard_card_shows(): void
    {
        $this->part($this->shelf('component-processor'), 'No Specs At All');

        $summary = app(PcBuilderHealth::class)->summary();

        $this->assertSame(1, $summary['spec_gaps']);
        $this->assertArrayHasKey('starved', $summary);
    }

    /*
     * The screen is read-only. /admin routes are Inertia renders and GET-only
     * by design; this one must not become a second place to edit a product.
     */
    public function test_the_screen_renders_for_catalogue_staff(): void
    {
        $this->part($this->shelf('component-processor'), 'A Chip');

        $admin = User::factory()->create(['role' => 'admin']);

        $props = $this->actingAs($admin)->get('/admin/pc-builder')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertNotEmpty($props['slots']);
        $this->assertArrayHasKey('spec_gaps', $props['summary']);
    }

    public function test_a_customer_cannot_open_it(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        // The admin area redirects rather than answering 403, the way every
        // other screen behind can:catalogue does.
        $this->actingAs($customer)->get('/admin/pc-builder')->assertRedirect();
    }

    /**
     * The engine keys its checks by the old taxonomy's names while the
     * catalogue uses the new one, which is why every check silently returned
     * "unknown" and this screen first reported nothing missing anywhere.
     */
    public function test_a_builder_component_id_resolves_to_a_compatibility_slot(): void
    {
        $this->assertSame(
            PcCompatibilityService::SLOT_CPU,
            PcCompatibilityService::slotFor('component-processor')
        );
        $this->assertSame(
            PcCompatibilityService::SLOT_CASE,
            PcCompatibilityService::slotFor('component-casing')
        );
        $this->assertNull(PcCompatibilityService::slotFor('accessories-mouse'));
    }

    /*
     * The guide is the point of the screen as much as the table is: the rules
     * it states existed only inside the service that implements them. It has
     * to name the fields as the product form labels them, or it is describing
     * a form nobody can find.
     */
    public function test_the_screen_explains_the_rules_in_plain_terms(): void
    {
        $guide = file_get_contents(resource_path('js/Pages/Admin/PcBuilder.jsx'));

        foreach ([
            'Active',          // the only switch involved
            'Category',        // what actually files a part into a slot
            'Specifications',  // what the compatibility check reads
            'Socket',          // a name that has to match exactly
            '120W',            // and the format the wattage parser needs
            'Component › Processor', // the category written as the picker writes it
        ] as $term) {
            $this->assertStringContainsString(
                $term,
                $guide,
                "the guide no longer mentions \"{$term}\", which an admin has no other way to learn"
            );
        }
    }
}
