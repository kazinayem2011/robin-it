<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bringing the brand shelves and the brand rows back into agreement.
 *
 * The two drifted because nothing kept them in step, and the drift is one
 * directional: shelves exist that `brands` has never heard of, 453 of them by
 * name. What the command must never do is close that gap by guessing — the
 * same list contains Acer and Huawei alongside Cable, Casing, DVR, Earphone
 * and Firewall, which are things a shop sells, not people who make them.
 */
class ReconcileBrandShelvesTest extends TestCase
{
    use RefreshDatabase;

    private function shelf(string $name, ?int $parent = null): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'parent_id' => $parent ?? Category::create([
                'name' => 'Root '.uniqid(),
                'slug' => 'root-'.uniqid(),
            ])->id,
            'is_active' => true,
        ]);
    }

    public function test_it_links_a_shelf_to_a_brand_that_already_exists(): void
    {
        $brand = Brand::create(['name' => 'Acer', 'slug' => 'acer']);
        $shelf = $this->shelf('Acer');

        $this->artisan('brands:reconcile-shelves --apply')->assertSuccessful();

        $this->assertSame($brand->id, $shelf->fresh()->brand_id);
    }

    /** Shelves and brand rows disagree about case constantly. */
    public function test_it_matches_regardless_of_case_and_padding(): void
    {
        $brand = Brand::create(['name' => 'HUAWEI', 'slug' => 'huawei']);
        $shelf = $this->shelf('  huawei ');

        $this->artisan('brands:reconcile-shelves --apply')->assertSuccessful();

        $this->assertSame($brand->id, $shelf->fresh()->brand_id);
    }

    /** Reporting is the default, so a look costs nothing. */
    public function test_it_writes_nothing_without_being_told_to(): void
    {
        Brand::create(['name' => 'Acer', 'slug' => 'acer']);
        $shelf = $this->shelf('Acer');

        $this->artisan('brands:reconcile-shelves')->assertSuccessful();

        $this->assertNull($shelf->fresh()->brand_id);
    }

    /**
     * The line it must not cross. Deciding "Firewall" is a maker is a
     * judgement about the catalogue, and creating 453 brand rows on a guess
     * would put every one of them on the storefront's brand strip.
     */
    public function test_it_never_invents_a_brand_for_a_shelf_it_cannot_match(): void
    {
        $this->shelf('Firewall');
        $this->shelf('Earphone');

        $this->artisan('brands:reconcile-shelves --apply')->assertSuccessful();

        $this->assertSame(0, Brand::count());
    }

    /** A root shelf is a department, never a maker. */
    public function test_it_leaves_root_categories_alone(): void
    {
        Brand::create(['name' => 'Laptop', 'slug' => 'laptop-brand']);
        $root = Category::create(['name' => 'Laptop', 'slug' => 'laptop']);

        $this->artisan('brands:reconcile-shelves --apply')->assertSuccessful();

        $this->assertNull($root->fresh()->brand_id);
    }
}
