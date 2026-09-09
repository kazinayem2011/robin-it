<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which shelf comes first, and who decides.
 *
 * Nothing ordered categories anywhere. Neither the mega-menu query nor the
 * `children` relation carried an `orderBy`, so the menu, the footer and every
 * picker showed whatever the engine returned, and a shop that wanted Laptop
 * ahead of Office Equipment had no way to say so.
 */
class CategoryOrderTest extends TestCase
{
    use RefreshDatabase;

    private function shelf(string $name, int $position, ?int $parent = null): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'parent_id' => $parent,
            'position' => $position,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function names(?int $parent = null): array
    {
        return Category::where('parent_id', $parent)
            ->inMenuOrder()
            ->pluck('name')
            ->all();
    }

    public function test_the_shop_order_is_what_comes_back(): void
    {
        $this->shelf('Office Equipment', 2);
        $this->shelf('Laptop', 0);
        $this->shelf('Component', 1);

        $this->assertSame(
            ['Laptop', 'Component', 'Office Equipment'],
            $this->names(),
        );
    }

    /**
     * A fresh row defaults to position 0, so several can share it. Without a
     * second key their order between themselves is whatever the engine feels
     * like — which is the thing this is fixing.
     */
    public function test_shelves_sharing_a_position_fall_back_to_their_name(): void
    {
        $this->shelf('Zebra', 0);
        $this->shelf('Alpha', 0);

        $this->assertSame(['Alpha', 'Zebra'], $this->names());
    }

    /**
     * The relation carries the ordering, so no caller has to remember it.
     *
     * Asserted on the query, because the rows cannot show this. `parent_id` is
     * indexed together with `position`, so the engine answers "children of X"
     * by walking that index and hands them back in position order whether or
     * not the query asked — verified with EXPLAIN QUERY PLAN. Every assertion
     * on the returned order therefore passes with the ORDER BY removed, and
     * only the query itself says whether the relation still guarantees it.
     */
    public function test_the_children_relation_carries_the_ordering(): void
    {
        $root = $this->shelf('Component', 0);

        $this->assertMatchesRegularExpression(
            '/order by .position. asc, .name. asc/i',
            $root->children()->toSql(),
        );
    }

    /**
     * And the rows come back in it — the behaviour a page actually depends on,
     * stated once here rather than left implied by the query above. Positions
     * run against both creation and alphabetical order, so a relation ordered
     * by the wrong column fails this even though a missing ORDER BY does not.
     */
    public function test_children_come_back_in_that_order(): void
    {
        $root = $this->shelf('Component', 0);
        $this->shelf('Alpha', 2, $root->id);
        $this->shelf('Bravo', 1, $root->id);
        $this->shelf('Charlie', 0, $root->id);

        $this->assertSame(
            ['Charlie', 'Bravo', 'Alpha'],
            $root->children->pluck('name')->all(),
        );
    }

    public function test_an_admin_can_move_a_shelf_up(): void
    {
        $this->shelf('Desktop', 0);
        $this->shelf('Laptop', 1);
        $component = $this->shelf('Component', 2);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$component->id}/move", [
                'direction' => 'up',
            ])
            ->assertOk();

        $this->assertSame(['Desktop', 'Component', 'Laptop'], $this->names());
    }

    public function test_an_admin_can_move_a_shelf_down(): void
    {
        $desktop = $this->shelf('Desktop', 0);
        $this->shelf('Laptop', 1);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$desktop->id}/move", [
                'direction' => 'down',
            ])
            ->assertOk();

        $this->assertSame(['Laptop', 'Desktop'], $this->names());
    }

    /**
     * Positions are per parent, so moving a subcategory rearranges its own
     * shelf and never reckons with another one.
     *
     * The gap in Component's positions is the point. Positions are only unique
     * within a parent, so across the whole table a laptop subcategory sits
     * between Processor and Casing. A move that renumbers every category
     * rather than just the siblings therefore steps Casing over the laptop
     * one and leaves it exactly where it was on its own shelf — the button
     * does nothing, twice in a row, and then works on the third press.
     */
    public function test_a_subcategory_moves_only_among_its_siblings(): void
    {
        $componentRoot = $this->shelf('Component', 0);
        $laptopRoot = $this->shelf('Laptop', 1);

        $this->shelf('Processor', 0, $componentRoot->id);
        $casing = $this->shelf('Casing', 2, $componentRoot->id);
        $this->shelf('Gaming Laptop', 1, $laptopRoot->id);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$casing->id}/move", [
                'direction' => 'up',
            ])
            ->assertOk();

        $this->assertSame(
            ['Casing', 'Processor'],
            $this->names($componentRoot->id),
        );
        $this->assertSame($componentRoot->id, $casing->fresh()->parent_id);
        $this->assertSame(['Gaming Laptop'], $this->names($laptopRoot->id));
    }

    /**
     * Answered rather than refused. The buttons are disabled at the ends, so
     * reaching here means two people moved the same shelf at once.
     */
    public function test_moving_past_the_end_is_answered_not_refused(): void
    {
        $first = $this->shelf('Desktop', 0);
        $this->shelf('Laptop', 1);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$first->id}/move", [
                'direction' => 'up',
            ])
            ->assertOk();

        $this->assertSame(['Desktop', 'Laptop'], $this->names());
    }

    /**
     * Renumbered across the set rather than swapped. Rows that have never been
     * moved all sit at 0, and swapping two of those changes nothing at all.
     */
    public function test_a_move_between_two_unmoved_shelves_still_moves_one(): void
    {
        $this->shelf('Alpha', 0);
        $bravo = $this->shelf('Bravo', 0);
        $this->shelf('Charlie', 0);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$bravo->id}/move", [
                'direction' => 'up',
            ])
            ->assertOk();

        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], $this->names());
    }

    public function test_a_direction_is_required_and_checked(): void
    {
        $shelf = $this->shelf('Desktop', 0);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/categories/{$shelf->id}/move", [
                'direction' => 'sideways',
            ])
            ->assertStatus(422);
    }

    public function test_a_shopper_cannot_rearrange_the_shop(): void
    {
        $shelf = $this->shelf('Desktop', 0);

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->patchJson("/api/admin/categories/{$shelf->id}/move", [
                'direction' => 'down',
            ])
            ->assertStatus(403);
    }
}
