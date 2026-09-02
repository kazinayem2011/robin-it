<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use Database\Seeders\CatalogueAttributeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporting on the filters, which is the question worth asking after a deploy.
 *
 * Seeding the questions and answering them are separate jobs — the seeder
 * defines what a shelf may ask and never touches a product — so "did the
 * seeder run" and "does the shop filter" have different answers, and a shelf
 * whose questions nothing answers shows an empty sidebar.
 */
class CatalogueFiltersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_says_so_when_nothing_is_defined(): void
    {
        $this->artisan('catalogue:filters')
            ->expectsOutputToContain('No questions are defined')
            ->assertSuccessful();
    }

    public function test_it_lists_a_shelf_that_has_questions(): void
    {
        Category::create(['name' => 'Monitor', 'slug' => 'monitor', 'is_active' => true]);
        $this->seed(CatalogueAttributeSeeder::class);

        $this->artisan('catalogue:filters')
            ->expectsOutputToContain('monitor')
            ->assertSuccessful();
    }

    /**
     * The failure a differing catalogue would produce: the questions are
     * defined and hang on nothing, so the sidebar never changes. Seeding must
     * say so rather than look like it worked.
     */
    public function test_seeding_names_the_shelves_it_could_not_find(): void
    {
        Category::create(['name' => 'Monitor', 'slug' => 'monitor', 'is_active' => true]);

        $this->artisan('db:seed', ['--class' => CatalogueAttributeSeeder::class])
            ->expectsOutputToContain('1 of 13 shelves now ask questions.')
            ->expectsOutputToContain('No category matched these slugs')
            ->assertSuccessful();
    }

    public function test_seeding_a_full_catalogue_reports_every_shelf(): void
    {
        foreach ([
            'networking-router', 'monitor', 'laptop', 'power-ups', 'phone', 'tablet',
            'office-equipment-printer', 'component-ssd', 'accessories-pen-drive',
            'accessories-memory-card', 'accessories-keyboard', 'accessories-mouse',
            'accessories-headphone',
        ] as $slug) {
            Category::create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
        }

        $this->artisan('db:seed', ['--class' => CatalogueAttributeSeeder::class])
            ->expectsOutputToContain('13 of 13 shelves now ask questions.')
            ->assertSuccessful();
    }
}
