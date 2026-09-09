<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CategorySlugHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moving a shelf to a new address without breaking the old one.
 *
 * `/shop/{slug}` 404s on a slug the catalogue does not hold, which is right —
 * it is what turns a typo into something visible instead of an empty grid. But
 * it also means a rename silently breaks every link to the shelf: the shop's
 * own menus repair themselves and nothing else does, not a bookmark, not a
 * search engine's index, not a link in an email sent last month.
 */
class CategorySlugMoveTest extends TestCase
{
    use RefreshDatabase;

    private function shelf(string $name, string $slug, ?int $parent = null, ?int $brand = null): Category
    {
        return Category::create([
            'name' => $name, 'slug' => $slug,
            'parent_id' => $parent, 'brand_id' => $brand, 'is_active' => true,
        ]);
    }

    public function test_a_renamed_shelf_still_answers_at_its_old_address(): void
    {
        $shelf = $this->shelf('Processor', 'component-processor');

        $shelf->update(['slug' => 'processor']);

        $this->get('/shop/component-processor')
            ->assertRedirect('/shop/processor')
            ->assertStatus(301);
    }

    /** Permanent, so a search engine moves its index rather than keeping both. */
    public function test_the_redirect_is_permanent(): void
    {
        $shelf = $this->shelf('Processor', 'old-address');
        $shelf->update(['slug' => 'new-address']);

        $this->get('/shop/old-address')->assertStatus(301);
    }

    public function test_an_address_nobody_ever_had_is_still_a_miss(): void
    {
        $this->get('/shop/never-existed')->assertNotFound();
    }

    /**
     * The live shelf wins. If another category has since taken the address,
     * it belongs to whoever holds it now — redirecting away from a page that
     * exists would make it unreachable.
     */
    public function test_an_address_taken_by_another_shelf_belongs_to_that_shelf(): void
    {
        $first = $this->shelf('Processor', 'cpu');
        $first->update(['slug' => 'processor']);

        $this->shelf('Cooling', 'cpu');

        $this->get('/shop/cpu')->assertOk();
    }

    /** Moving back to an old address is living there, not redirecting to it. */
    public function test_a_shelf_that_moves_back_stops_redirecting(): void
    {
        $shelf = $this->shelf('Processor', 'processor');
        $shelf->update(['slug' => 'cpu']);
        $shelf->update(['slug' => 'processor']);

        $this->assertSame(0, CategorySlugHistory::where('slug', 'processor')->count());
        $this->get('/shop/processor')->assertOk();
    }

    /** A shelf nobody can see should not be advertised by a redirect. */
    public function test_an_inactive_shelf_does_not_redirect(): void
    {
        $shelf = $this->shelf('Processor', 'old-cpu');
        $shelf->update(['slug' => 'cpu']);
        $shelf->update(['is_active' => false]);

        $this->get('/shop/old-cpu')->assertNotFound();
    }

    /** History follows the shelf out. */
    public function test_deleting_a_shelf_takes_its_old_addresses_with_it(): void
    {
        $shelf = $this->shelf('Processor', 'old-cpu');
        $shelf->update(['slug' => 'cpu']);

        $shelf->delete();

        $this->assertSame(0, CategorySlugHistory::count());
    }

    /**
     * A brand shelf is addressed maker-first — `intel-processor`, not the path
     * down the tree — because that is the order somebody searches in.
     */
    public function test_a_new_brand_shelf_is_addressed_maker_first(): void
    {
        $brand = Brand::create(['name' => 'Intel', 'slug' => 'intel']);
        $parent = $this->shelf('Processor', 'component-processor');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/categories', [
                'name' => 'Intel',
                'parent_id' => $parent->id,
                'brand_id' => $brand->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('categories', ['slug' => 'intel-processor']);
    }

    /** A slug typed by hand is the shop's decision and is left alone. */
    public function test_a_typed_address_is_never_second_guessed(): void
    {
        $brand = Brand::create(['name' => 'Intel', 'slug' => 'intel']);
        $parent = $this->shelf('Processor', 'component-processor');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/categories', [
                'name' => 'Intel',
                'parent_id' => $parent->id,
                'brand_id' => $brand->id,
                'slug' => 'intel-cpus-bd',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('categories', ['slug' => 'intel-cpus-bd']);
    }

    /** An ordinary shelf keeps naming itself after itself. */
    public function test_a_shelf_with_no_brand_is_addressed_by_its_name(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/categories', ['name' => 'Gaming Laptop'])
            ->assertCreated();

        $this->assertDatabaseHas('categories', ['slug' => 'gaming-laptop']);
    }
}
