<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Narrowing the shop to what can be ordered ahead of a delivery.
 *
 * The shop has sold ahead of its stock for a while — a product may go below
 * zero, bounded by its pre-order limit — but nothing listed those products
 * together, so the only way to find one was to already know its name. The
 * header's search scope offers it now, and this is the filter underneath.
 */
class PreorderScopeTest extends TestCase
{
    use RefreshDatabase;

    private function shelf(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'gpu'],
            ['name' => 'Graphics Cards', 'is_active' => true],
        );
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->shelf()->id,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'price' => 50000,
            'stock_quantity' => 0,
            'is_active' => true,
        ], $attributes));
    }

    /** @return array<int, string> */
    private function listed(string $query): array
    {
        return collect($this->getJson("/api/products?{$query}")->json('data'))
            ->pluck('name')
            ->all();
    }

    public function test_it_lists_only_what_can_be_ordered_ahead(): void
    {
        $this->product('Awaiting Delivery', ['allow_preorder' => true]);
        $this->product('Plain Out Of Stock');
        $this->product('On The Shelf', ['stock_quantity' => 5]);

        $this->assertSame(['Awaiting Delivery'], $this->listed('preorder=1'));
    }

    /**
     * The shelf being empty is the point, so this is the opposite of in_stock
     * rather than a narrowing of it. A pre-order product that has units is
     * simply in stock, and listing it here would tell somebody they were
     * waiting for something they could have today.
     */
    public function test_a_preorder_product_with_stock_is_not_waiting_for_anything(): void
    {
        $this->product('Arrived Already', [
            'allow_preorder' => true,
            'stock_quantity' => 3,
        ]);

        $this->assertSame([], $this->listed('preorder=1'));
        $this->assertSame(['Arrived Already'], $this->listed('in_stock=1'));
    }

    public function test_the_two_scopes_do_not_overlap(): void
    {
        $this->product('Awaiting Delivery', ['allow_preorder' => true]);
        $this->product('On The Shelf', ['stock_quantity' => 5]);

        $this->assertSame(['Awaiting Delivery'], $this->listed('preorder=1'));
        $this->assertSame(['On The Shelf'], $this->listed('in_stock=1'));
    }

    /** A draft is withheld from every listing, this one included. */
    public function test_a_draft_is_not_offered_for_preorder(): void
    {
        $this->product('Hidden', [
            'allow_preorder' => true,
            'is_active' => false,
        ]);

        $this->assertSame([], $this->listed('preorder=1'));
    }

    public function test_without_the_filter_everything_is_listed(): void
    {
        $this->product('Awaiting Delivery', ['allow_preorder' => true]);
        $this->product('On The Shelf', ['stock_quantity' => 5]);

        $this->assertCount(2, $this->listed(''));
    }
}
