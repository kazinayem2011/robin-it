<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shelves one level down, across the top of a category page.
 *
 * Star Tech's layout: on Office Equipment, Projector, Conference System, PA
 * System…; on Projector, its own shelves; on a shelf with none below, no row.
 * It used to list the makers stocked anywhere beneath, which on a department
 * put a row of brands where the way into its own shelves belonged.
 */
class CategorySubRowTest extends TestCase
{
    use RefreshDatabase;

    private Category $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Category::create([
            'name' => 'Office Equipment', 'slug' => 'office-equipment', 'position' => 0, 'is_active' => true,
        ]);
    }

    private function shelf(string $name, Category $parent, int $position = 0, bool $stocked = true): Category
    {
        $shelf = Category::create([
            'name' => $name, 'slug' => str($name)->slug(),
            'parent_id' => $parent->id, 'position' => $position, 'is_active' => true,
        ]);

        if ($stocked) {
            $product = Product::create([
                'name' => $name.' item', 'slug' => str($name)->slug().'-item',
                'category_id' => $shelf->id, 'price' => 1000, 'stock_quantity' => 3, 'is_active' => true,
            ]);
            $product->categories()->syncWithoutDetaching([$shelf->id]);
        }

        return $shelf;
    }

    private function row(string $slug = 'office-equipment'): array
    {
        return app(CategoryService::class)->subcategoriesOf($slug);
    }

    public function test_it_lists_the_shelves_one_level_down_in_menu_order(): void
    {
        $this->shelf('PA System', $this->office, 2);
        $this->shelf('Projector', $this->office, 0);
        $this->shelf('Conference System', $this->office, 1);

        $this->assertSame(
            ['Projector', 'Conference System', 'PA System'],
            collect($this->row())->pluck('name')->all(),
        );
    }

    /* Not the shelves beneath those: Projector's own are on Projector's page. */
    public function test_it_does_not_reach_two_levels_down(): void
    {
        $projector = $this->shelf('Projector', $this->office);
        $this->shelf('Epson Projector', $projector);

        $this->assertSame(['Projector'], collect($this->row())->pluck('name')->all());
        $this->assertSame(['Epson Projector'], collect($this->row('projector'))->pluck('name')->all());
    }

    /* A shelf holding nothing would open onto "No products found". */
    public function test_it_leaves_out_an_empty_shelf(): void
    {
        $this->shelf('Projector', $this->office);
        $this->shelf('Kiosk', $this->office, 1, stocked: false);

        $this->assertSame(['Projector'], collect($this->row())->pluck('name')->all());
    }

    /* Stocked further down counts, as it does in the mega menu. */
    public function test_a_shelf_stocked_only_below_is_kept(): void
    {
        $printer = $this->shelf('Printer', $this->office, 0, stocked: false);
        $this->shelf('Laser Printer', $printer);

        $this->assertSame(['Printer'], collect($this->row())->pluck('name')->all());
    }

    public function test_it_leaves_out_a_hidden_shelf(): void
    {
        $this->shelf('Projector', $this->office);
        $this->shelf('Kiosk', $this->office, 1)->update(['is_active' => false]);

        $this->assertSame(['Projector'], collect($this->row())->pluck('name')->all());
    }

    /* The bottom of the tree has no row, as on Star Tech. */
    public function test_a_shelf_with_nothing_below_has_no_row(): void
    {
        $this->shelf('Projector', $this->office);

        $this->assertSame([], $this->row('projector'));
    }

    /* The admin adds a shelf: it shows now, not after the cache runs out. */
    public function test_a_new_shelf_shows_at_once(): void
    {
        $this->shelf('Projector', $this->office);
        $this->assertCount(1, $this->row());

        $this->shelf('Kiosk', $this->office, 1);

        $this->assertSame(['Projector', 'Kiosk'], collect($this->row())->pluck('name')->all());
    }

    public function test_the_endpoint_answers_with_the_row(): void
    {
        $this->shelf('Projector', $this->office);

        $this->getJson('/api/categories/office-equipment/subcategories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Projector')
            ->assertJsonPath('data.0.slug', 'projector');
    }

    public function test_a_shelf_nobody_has_is_an_empty_row_not_an_error(): void
    {
        $this->getJson('/api/categories/no-such-shelf/subcategories')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
