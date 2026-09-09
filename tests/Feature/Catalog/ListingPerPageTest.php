<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Show: 20" on the listing, and the ceiling the control has to respect.
 *
 * The cap is not a preference — it stops `?per_page=500000` being used to read
 * the whole catalogue in one request. Which makes it a contract the front end
 * has to know: the first "Show" menu offered 100, and every choice of it
 * answered 422 and drew an empty grid.
 */
class ListingPerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $shelf = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);

        foreach (range(1, 45) as $i) {
            $product = Product::create([
                'name' => "Laptop {$i}", 'slug' => "laptop-{$i}",
                'category_id' => $shelf->id, 'price' => 1000 + $i,
                'stock_quantity' => 3, 'is_active' => true,
            ]);
            $product->categories()->syncWithoutDetaching([$shelf->id]);
        }
    }

    /** Every option the "Show" menu offers has to be one the API will serve. */
    public function test_each_size_the_shop_offers_is_accepted(): void
    {
        foreach ([20, 40, 60] as $size) {
            $this->getJson("/api/products?category_slug=laptop&per_page={$size}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', $size);
        }
    }

    public function test_it_serves_that_many(): void
    {
        $this->getJson('/api/products?category_slug=laptop&per_page=40')
            ->assertOk()
            ->assertJsonPath('meta.total', 45)
            ->assertJsonCount(40, 'data');
    }

    /** The whole point of the cap. */
    public function test_it_refuses_more_than_it_will_serve(): void
    {
        $this->getJson('/api/products?category_slug=laptop&per_page='.(ProductService::MAX_PER_PAGE + 1))
            ->assertStatus(422);
    }

    public function test_the_ceiling_itself_is_allowed(): void
    {
        $this->getJson('/api/products?category_slug=laptop&per_page='.ProductService::MAX_PER_PAGE)
            ->assertOk();
    }

    /**
     * Asking for nothing gets the default rather than an error, because a
     * shopper arriving from a plain link sends no page size at all.
     */
    public function test_saying_nothing_gets_the_default(): void
    {
        $this->getJson('/api/products?category_slug=laptop')
            ->assertOk()
            ->assertJsonPath('meta.per_page', ProductService::DEFAULT_PER_PAGE);
    }
}
