<?php

namespace Tests\Feature\Catalog;

use Database\Seeders\StarTechTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The category links written by hand point at categories that exist.
 *
 * The footer's five shop links and one homepage promo were addressing
 * "laptops", "components", "desktops", "monitors" and "gaming". The catalogue
 * calls those "laptop", "component", "desktop", "monitor", and has no "gaming"
 * at all. Every one of them answered 200 and drew an empty grid, which is what
 * a category with nothing in stock looks like, so six dead links sat in the
 * footer of every page without anything to distinguish them from a quiet week.
 *
 * The plural is the tell: these were typed from the English word rather than
 * read off the taxonomy, and typing them again is how they drift again. The
 * shop carried two taxonomies over its life and the plural slugs belong to the
 * one it no longer runs, so the links were written against a tree that had
 * already been replaced. This checks them against the tree the shop is on.
 *
 * NavigationDeadEndsTest covers the same failure for the mega menu, but that
 * menu is built from CategoryService and so cannot name a category that is not
 * there. Only the links typed by hand can, which is why these need their own.
 */
class CategoryLinkTest extends TestCase
{
    use RefreshDatabase;

    /** Every `SHOP_CATEGORY('x')` written literally in the front end. */
    private function hardCodedSlugs(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'))
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'jsx' || str_contains($file->getPathname(), '__tests__')) {
                continue;
            }

            preg_match_all(
                "/SHOP_CATEGORY\(\s*['\"]([a-z0-9-]+)['\"]\s*\)/",
                file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[1] as $slug) {
                $found[$slug][] = str_replace(resource_path('js').'/', '', $file->getPathname());
            }
        }

        return $found;
    }

    public function test_every_hard_coded_category_link_names_a_real_category(): void
    {
        $this->seed(StarTechTaxonomySeeder::class);

        $slugs = $this->hardCodedSlugs();
        $this->assertNotEmpty($slugs, 'Found no hard-coded category links to check.');

        foreach ($slugs as $slug => $files) {
            $this->assertDatabaseHas('categories', ['slug' => $slug, 'is_active' => true]);

            // and the page itself, since that is what a visitor gets
            $this->get("/shop/{$slug}")->assertOk();
        }
    }

    public function test_a_slug_no_category_has_is_a_404_rather_than_an_empty_shelf(): void
    {
        $this->seed(StarTechTaxonomySeeder::class);

        $this->get('/shop/laptop')->assertOk();
        $this->get('/shop/laptops')->assertNotFound();
        $this->get('/shop/gaming')->assertNotFound();
    }
}
