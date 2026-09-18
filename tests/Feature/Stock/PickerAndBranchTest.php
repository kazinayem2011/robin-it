<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two things the receiving screens could not say.
 *
 * A catalogue laid out by maker has four products called "Sample AJAZZ", one
 * on each AJAZZ shelf, and the picker offered four identical lines. And a
 * delivery with no branch named landed wherever the server falls back to,
 * which on the live shop is a branch the person receiving never chose.
 */
class PickerAndBranchTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Store $online;

    private Store $showroom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->admin()->create();

        $this->online = $this->branch('Khulna Branch', true, 1);
        $this->showroom = $this->branch('Uttara Showroom', false, 2);
    }

    private function branch(string $name, bool $online, int $order): Store
    {
        return Store::create([
            'name' => $name, 'city' => 'Dhaka', 'address' => 'Test address',
            'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true,
            'fulfils_online' => $online, 'sort_order' => $order,
        ]);
    }

    /** What one branch is holding of this product. */
    private function heldAt(Product $product, Store $store): int
    {
        return (int) ProductStock::where('product_id', $product->id)
            ->where('store_id', $store->id)
            ->sum('quantity');
    }

    private function product(string $shelf, string $slug): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => $slug.'-shelf'],
            ['name' => $shelf, 'is_active' => true]
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Sample AJAZZ',
            'slug' => $slug,
            'price' => 2500,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    /** Four products of the same name are only told apart by their shelf. */
    public function test_the_picker_says_which_shelf_each_product_is_on(): void
    {
        $this->product('AJAZZ Keyboards', 'ajazz-keyboard');
        $this->product('AJAZZ Mice', 'ajazz-mouse');

        $found = $this->actingAs($this->staff)
            ->getJson('/api/admin/stock/units?search=AJAZZ')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $found);
        $this->assertEqualsCanonicalizing(
            ['AJAZZ Keyboards', 'AJAZZ Mice'],
            array_column(array_column($found, 'category'), 'name'),
        );
    }

    public function test_a_delivery_goes_into_the_branch_that_was_chosen(): void
    {
        $product = $this->product('AJAZZ Mice', 'ajazz-mouse');

        $this->actingAs($this->staff)->postJson('/api/admin/stock/receipts', [
            'store_id' => $this->showroom->id,
            'received_on' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 900]],
        ])->assertSuccessful();

        $this->assertSame(10, $this->heldAt($product, $this->showroom));
        $this->assertSame(0, $this->heldAt($product, $this->online));
    }

    /**
     * With no branch named the server still has to choose one, and it chooses
     * the branch that ships online orders. The screen now names that branch
     * rather than leaving it to be discovered afterwards.
     */
    public function test_a_delivery_with_no_branch_falls_back_to_the_online_one(): void
    {
        $product = $this->product('AJAZZ Mice', 'ajazz-mouse');

        $this->actingAs($this->staff)->postJson('/api/admin/stock/receipts', [
            'received_on' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 900]],
        ])->assertSuccessful();

        $this->assertSame(4, $this->heldAt($product, $this->online));
    }
}
