<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Support\SearchSpelling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Did you mean …?" when a search finds nothing.
 *
 * Search matches what was typed, as typed: "Vivobok" found nothing with an
 * ASUS Vivobook on the shelf, and the shopper was left at an empty page.
 */
class SearchSpellingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SearchSpelling::forget();

        $category = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'is_active' => true]);
        foreach (['ASUS Vivobook Go 15' => 'vivobook', 'Logitech Wireless Mouse' => 'mouse'] as $name => $slug) {
            Product::create([
                'category_id' => $category->id, 'name' => $name, 'slug' => $slug,
                'price' => 1000, 'stock_quantity' => 5, 'is_active' => true,
            ]);
        }
    }

    public function test_a_typo_is_offered_the_word_the_shop_uses(): void
    {
        $this->getJson('/api/products?search=Vivobok')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.did_you_mean', 'Vivobook');
    }

    public function test_two_letters_swapped_are_caught(): void
    {
        $this->getJson('/api/products?search='.urlencode('wireles mosue'))
            ->assertJsonPath('meta.did_you_mean', 'Wireless Mouse');
    }

    public function test_a_search_that_finds_something_gets_no_suggestion(): void
    {
        $this->getJson('/api/products?search=Vivobook')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('meta.did_you_mean');
    }

    public function test_nothing_is_offered_when_nothing_is_close(): void
    {
        $this->getJson('/api/products?search=zzqqxx')
            ->assertJsonPath('meta.did_you_mean', null);
    }

    /* A correction that would still find nothing is no help. */
    public function test_a_correction_is_offered_only_if_it_finds_something(): void
    {
        // "Vivobook" exists, but not with this price ceiling.
        $this->getJson('/api/products?search=Vivobok&max_price=10')
            ->assertJsonPath('meta.did_you_mean', null);
    }

    /*
     * "Cycel" is two letters from "Cycle" and two from "Zyxel". The tie went
     * to the commoner word, "QA Zyxel" found nothing, and nothing was offered.
     */
    public function test_a_tie_goes_to_the_word_that_finds_something(): void
    {
        $category = Category::first();
        foreach (['QA Cycle Laptop' => 'qa-cycle', 'Zyxel Router' => 'zyxel-r', 'Zyxel Switch' => 'zyxel-s'] as $name => $slug) {
            Product::create([
                'category_id' => $category->id, 'name' => $name, 'slug' => $slug,
                'price' => 1000, 'stock_quantity' => 5, 'is_active' => true,
            ]);
        }
        SearchSpelling::forget();

        $this->getJson('/api/products?search='.urlencode('QA Cycel'))
            ->assertJsonPath('meta.did_you_mean', 'QA Cycle');
    }

    public function test_the_search_box_offers_it_too(): void
    {
        $this->getJson('/api/products/suggestions?q=Vivobok')
            ->assertOk()
            ->assertJsonPath('data.did_you_mean', 'Vivobook');
    }
}
