<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The History tab: every change to stock, in plain words, filtered by kind.
 *
 * It listed corrections alone, so a delivery, a transfer or a returned parcel
 * could only be found one product at a time.
 */
class StockHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);

        $khulna = Store::create(['name' => 'Khulna', 'city' => 'Khulna', 'address' => 'A', 'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true, 'fulfils_online' => true, 'sort_order' => 1]);
        $dhaka = Store::create(['name' => 'Dhaka', 'city' => 'Dhaka', 'address' => 'B', 'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true, 'sort_order' => 2]);

        $category = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'ASUS Vivobook', 'slug' => 'asus', 'price' => 1000, 'stock_quantity' => 0, 'is_active' => true]);

        $stock = app(StockService::class);
        $stock->receive(['store_id' => $khulna->id], [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 800]]);
        $stock->transfer($product->fresh(), null, 2, $khulna->id, $dhaka->id);
        $stock->record($product->fresh(), null, -1, StockMovement::ADJUSTMENT, ['store_id' => $dhaka->id, 'reason' => 'damaged']);
    }

    private function rows(string $query = ''): array
    {
        return $this->actingAs($this->admin)
            ->get('/admin/stock/adjustments'.$query)
            ->viewData('page')['props']['movements']['data'];
    }

    public function test_it_lists_every_kind_of_change_by_default(): void
    {
        $what = collect($this->rows())->pluck('what');

        $this->assertTrue($what->contains(fn ($w) => str_starts_with($w, 'Delivery')));
        $this->assertTrue($what->contains('Transferred in'));
        $this->assertTrue($what->contains('Transferred out'));
        $this->assertTrue($what->contains(fn ($w) => str_starts_with($w, 'Corrected')));
    }

    public function test_it_filters_to_one_kind(): void
    {
        $this->assertCount(2, $this->rows('?kind=transfers'));
        $this->assertCount(1, $this->rows('?kind=deliveries'));
        $this->assertCount(1, $this->rows('?kind=corrections'));
    }

    public function test_the_filter_is_offered_in_plain_words(): void
    {
        $kinds = collect($this->actingAs($this->admin)->get('/admin/stock/adjustments')
            ->viewData('page')['props']['kinds'])->pluck('label');

        $this->assertSame(
            ['Everything', 'Deliveries', 'Sold', 'Came back (cancelled or returned)', 'Transfers', 'Corrections and write-offs'],
            $kinds->all(),
        );
    }
}
