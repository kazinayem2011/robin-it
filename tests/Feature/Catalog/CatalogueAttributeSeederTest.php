<?php

namespace Tests\Feature\Catalog;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use Database\Seeders\CatalogueAttributeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding a shelf's filters is data, not code.
 *
 * The seeder holds the definitions read off StarTech's router, monitor and
 * laptop pages. What matters here is that each shelf gets its own questions
 * and no others — attribute names are global, so a shelf that borrowed
 * another's would put "RAM Size" on a router.
 */
class CatalogueAttributeSeederTest extends TestCase
{
    use RefreshDatabase;

    private function shelf(string $name, string $slug): Category
    {
        return Category::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
    }

    private function questionsFor(string $slug): array
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        return Attribute::whereHas('categories', fn ($q) => $q->where('categories.id', $category->id))
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();
    }

    private function seedCatalogue(): void
    {
        foreach ([
            'Router' => 'networking-router',
            'Monitor' => 'monitor',
            'Laptop' => 'laptop',
            'Phone' => 'phone',
            'Tablet' => 'tablet',
            'Keyboard' => 'accessories-keyboard',
            'Mouse' => 'accessories-mouse',
        ] as $name => $slug) {
            $this->shelf($name, $slug);
        }

        $this->seed(CatalogueAttributeSeeder::class);
    }

    public function test_each_shelf_gets_its_own_questions(): void
    {
        $this->seedCatalogue();

        $this->assertContains('Wi-Fi Standard', $this->questionsFor('networking-router'));
        $this->assertContains('Panel Type', $this->questionsFor('monitor'));
        $this->assertContains('RAM Size', $this->questionsFor('laptop'));
    }

    public function test_a_shelf_does_not_borrow_another_shelfs_questions(): void
    {
        $this->seedCatalogue();

        $router = $this->questionsFor('networking-router');

        $this->assertNotContains('RAM Size', $router);
        $this->assertNotContains('Panel Type', $router);
        $this->assertNotContains('Screen Size', $router);
    }

    /**
     * Monitor measures a screen and so does a laptop, in different brackets.
     * They are separate questions on purpose; sharing a name would give a
     * laptop the monitor's inch bands.
     */
    public function test_the_two_screen_questions_stay_apart(): void
    {
        $this->seedCatalogue();

        $this->assertContains('Screen Size', $this->questionsFor('monitor'));
        $this->assertContains('Display Size', $this->questionsFor('laptop'));

        $monitorBands = Attribute::where('slug', 'screen-size')->firstOrFail()->values->pluck('label');
        $laptopBands = Attribute::where('slug', 'display-size')->firstOrFail()->values->pluck('label');

        $this->assertTrue($monitorBands->intersect($laptopBands)->isEmpty());
    }

    /** A band knows what it covers, so a measured product can be placed. */
    public function test_a_measurement_lands_in_the_right_band(): void
    {
        $this->seedCatalogue();

        $screen = Attribute::where('slug', 'screen-size')->firstOrFail()->load('values');

        $this->assertSame('23-25 inch', $screen->bandFor(24)->label);
        $this->assertSame('41 inch & Above', $screen->bandFor(49)->label);
    }

    /** Run twice, and nothing doubles. */
    public function test_running_it_again_changes_nothing(): void
    {
        $this->seedCatalogue();

        $attributes = Attribute::count();
        $values = AttributeValue::count();

        $this->seed(CatalogueAttributeSeeder::class);

        $this->assertSame($attributes, Attribute::count());
        $this->assertSame($values, AttributeValue::count());
    }

    /**
     * Two shelves may ask what a shopper calls the same thing.
     *
     * Slugs are global, so a phone's RAM and a tablet's cannot both be `ram` —
     * but both should read "RAM" in the sidebar, where the shelf is the
     * context. The name is what is shown; the slug is what must be unique.
     */
    public function test_two_shelves_can_both_ask_for_ram(): void
    {
        $this->seedCatalogue();

        $phone = Attribute::where('slug', 'phone-ram')->firstOrFail();
        $tablet = Attribute::where('slug', 'tablet-ram')->firstOrFail();

        $this->assertSame('RAM', $phone->name);
        $this->assertSame('RAM', $tablet->name);

        // ...and each keeps its own answers.
        $this->assertNotSame($phone->id, $tablet->id);
        $this->assertContains('RAM', $this->questionsFor('phone'));
        $this->assertContains('RAM', $this->questionsFor('tablet'));
    }

    /** Keyboard and Mouse both ask "Type" and both mean their own. */
    public function test_a_shared_label_does_not_share_a_question(): void
    {
        $this->seedCatalogue();

        $keyboard = Attribute::where('slug', 'keyboard-type')->firstOrFail();
        $mouse = Attribute::where('slug', 'mouse-type')->firstOrFail();

        $this->assertSame('Type', $keyboard->name);
        $this->assertSame('Type', $mouse->name);
        $this->assertTrue(
            $keyboard->values->pluck('label')->intersect($mouse->values->pluck('label'))->count() < 3,
            'The keyboard and mouse Type questions share most of their answers.'
        );
    }

    /** A category the shop has not created yet is skipped, not fatal. */
    public function test_a_missing_category_is_skipped(): void
    {
        $this->shelf('Monitor', 'monitor');

        $this->seed(CatalogueAttributeSeeder::class);

        $this->assertContains('Panel Type', $this->questionsFor('monitor'));
    }
}
