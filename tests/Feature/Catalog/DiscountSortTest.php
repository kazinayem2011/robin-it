<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The offers page listed discounts in arrival order, so a 5% saving could sit
 * above a 15% one. It leads with the deepest cut now.
 */
class DiscountSortTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'GPUs', 'slug' => 'gpu', 'is_active' => true]);
        $this->brand = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
    }

    private function product(string $name, float $price, ?float $discount = null): Product
    {
        return Product::create([
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'price' => $price,
            'discount_price' => $discount,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }

    /** @return array<int, string> */
    private function namesSortedByDiscount(array $extra = []): array
    {
        return collect(
            $this->getJson('/api/products?'.http_build_query(array_merge(['sort' => 'discount_high'], $extra)))
                ->assertStatus(200)
                ->json('data')
        )->pluck('name')->all();
    }

    public function test_the_deepest_discount_comes_first(): void
    {
        $this->product('Shallow', 100000, 95000);   // 5%
        $this->product('Deepest', 100000, 60000);   // 40%
        $this->product('Middle', 100000, 80000);    // 20%

        $this->assertSame(['Deepest', 'Middle', 'Shallow'], $this->namesSortedByDiscount());
    }

    /**
     * Percentage, not the amount saved: ৳900 off a ৳3,000 cooler is the better
     * deal than ৳900 off a ৳90,000 card, and a shopper would say so.
     */
    public function test_it_ranks_by_proportion_not_by_amount_saved(): void
    {
        $this->product('Cheap part, third off', 3000, 2100);       // 30%, saves 900
        $this->product('Dear part, 1 percent off', 90000, 89100);  // 1%, saves 900

        $this->assertSame(
            ['Cheap part, third off', 'Dear part, 1 percent off'],
            $this->namesSortedByDiscount()
        );
    }

    /** Undiscounted stock scores zero and sinks, rather than being dropped. */
    public function test_full_price_products_sort_last_but_still_appear(): void
    {
        $this->product('Full price', 50000);
        $this->product('On offer', 50000, 45000);

        $this->assertSame(['On offer', 'Full price'], $this->namesSortedByDiscount());
    }

    /** A discount_price above the price is bad data, not a negative discount. */
    public function test_a_discount_higher_than_the_price_is_ignored(): void
    {
        $this->product('Nonsense discount', 10000, 12000);
        $this->product('Real discount', 10000, 9000);

        $this->assertSame(['Real discount', 'Nonsense discount'], $this->namesSortedByDiscount());
    }

    public function test_the_endpoint_accepts_the_sort(): void
    {
        $this->getJson('/api/products?sort=discount_high')->assertStatus(200);
        $this->getJson('/api/products?sort=deepest_ever')->assertStatus(422);
    }

    /** The offers page combines it with the on-sale filter. */
    public function test_it_composes_with_the_on_sale_filter(): void
    {
        $this->product('Full price', 50000);
        $this->product('Small cut', 50000, 47500);
        $this->product('Big cut', 50000, 30000);

        $this->assertSame(['Big cut', 'Small cut'], $this->namesSortedByDiscount(['on_sale' => 1]));
    }
}
