<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What else to show somebody looking at a product.
 *
 * The section was hand-picked only, and nothing had been picked for any of the
 * shop's twelve hundred products — so it rendered on none of them, and a
 * shopper looking at a mouse was never offered another mouse.
 */
class SimilarProductsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProductService
    {
        return app(ProductService::class);
    }

    private function category(string $name, ?int $parentId = null): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'parent_id' => $parentId,
            'is_active' => true,
        ]);
    }

    private function product(Category $category, string $name, float $price, int $stock = 5): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'price' => $price,
            'stock_quantity' => $stock,
            'is_active' => true,
        ]);
    }

    public function test_it_suggests_from_the_same_shelf(): void
    {
        $mice = $this->category('Mouse');
        $subject = $this->product($mice, 'MX Master', 12000);
        $this->product($mice, 'MX Anywhere', 9000);
        $this->product($mice, 'G502', 8000);

        $names = $this->service()->similarProducts($subject)->pluck('name');

        $this->assertCount(2, $names);
        $this->assertNotContains('MX Master', $names);
    }

    /** Nearest in price first, so an alternative is actually an alternative. */
    public function test_the_nearest_in_price_come_first(): void
    {
        $mice = $this->category('Mouse');
        $subject = $this->product($mice, 'MX Master', 12000);
        $this->product($mice, 'Budget Mouse', 900);
        $this->product($mice, 'MX Anywhere', 11000);
        $this->product($mice, 'Flagship Mouse', 60000);

        $first = $this->service()->similarProducts($subject)->first();

        $this->assertSame('MX Anywhere', $first->name);
    }

    /** Where somebody has chosen, their choice wins outright. */
    public function test_hand_picked_beat_anything_worked_out(): void
    {
        $mice = $this->category('Mouse');
        $pads = $this->category('Mouse Pad');

        $subject = $this->product($mice, 'MX Master', 12000);
        $this->product($mice, 'MX Anywhere', 11500);
        $pad = $this->product($pads, 'Desk Mat', 1200);

        $subject->relatedProducts()->attach($pad->id);

        $names = $this->service()->similarProducts($subject)->pluck('name');

        $this->assertSame(['Desk Mat'], $names->all());
    }

    /**
     * A thin shelf widens to its parent: "Gaming Mouse" may hold three
     * products where "Accessories" holds eighty, and one lonely suggestion
     * is barely a suggestion.
     */
    public function test_a_thin_shelf_widens_to_its_neighbours(): void
    {
        $accessories = $this->category('Accessories');
        $mice = $this->category('Mouse', $accessories->id);
        $pads = $this->category('Mouse Pad', $accessories->id);

        $subject = $this->product($mice, 'MX Master', 12000);
        $this->product($pads, 'Desk Mat', 1200);
        $this->product($pads, 'Wrist Rest', 900);

        $names = $this->service()->similarProducts($subject)->pluck('name');

        $this->assertContains('Desk Mat', $names);
        $this->assertNotContains('MX Master', $names);
    }

    /**
     * What can be bought leads, but nothing is dropped for being out of stock.
     *
     * Excluding it emptied the section on a shop where two products in twelve
     * hundred had stock — which is the state a shop is in the week it opens,
     * and exactly when it most needs to show its range.
     */
    public function test_in_stock_leads_and_sold_out_still_shows(): void
    {
        $mice = $this->category('Mouse');
        $subject = $this->product($mice, 'MX Master', 12000);
        $this->product($mice, 'Sold Out Twin', 12000, 0);
        $this->product($mice, 'In Stock, Further Off', 5000, 4);

        $names = $this->service()->similarProducts($subject)->pluck('name');

        $this->assertSame('In Stock, Further Off', $names->first());
        $this->assertContains('Sold Out Twin', $names);
    }

    public function test_it_never_suggests_a_hidden_product(): void
    {
        $mice = $this->category('Mouse');
        $subject = $this->product($mice, 'MX Master', 12000);
        $this->product($mice, 'Retired Mouse', 11000)->update(['is_active' => false]);

        $this->assertCount(0, $this->service()->similarProducts($subject));
    }

    public function test_it_is_capped(): void
    {
        $mice = $this->category('Mouse');
        $subject = $this->product($mice, 'MX Master', 12000);

        for ($i = 0; $i < 12; $i++) {
            $this->product($mice, "Mouse {$i}", 10000 + $i);
        }

        $this->assertCount(6, $this->service()->similarProducts($subject));
    }

    /**
     * The only product on a top-level shelf has nothing to widen to, and must
     * come back empty rather than reaching across the whole shop — a mouse
     * beside a graphics card is the arbitrary suggestion this was built to
     * avoid.
     */
    public function test_a_lone_product_with_no_parent_shelf_suggests_nothing(): void
    {
        $subject = $this->product($this->category('Mouse'), 'MX Master', 12000);
        $this->product($this->category('Graphics Card'), 'RTX 4090', 250000);

        $this->assertCount(0, $this->service()->similarProducts($subject));
    }

    /** And the product page carries them. */
    public function test_the_product_page_carries_them(): void
    {
        $mice = $this->category('Mouse');
        $subject = $this->product($mice, 'MX Master', 12000);
        $this->product($mice, 'MX Anywhere', 11000);

        $loaded = $this->service()->getProductBySlug($subject->slug);

        $this->assertTrue($loaded->relationLoaded('similarProducts'));
        $this->assertSame('MX Anywhere', $loaded->similarProducts->first()->name);
    }
}
