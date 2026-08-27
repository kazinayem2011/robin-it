<?php

namespace Tests\Feature\Stock;

use App\Exceptions\StorefrontException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock split across the branches that hold it.
 *
 * The invariant underneath all of this: the shop-wide total is the sum of the
 * branch balances, always. Anything that breaks that makes every badge, report
 * and low-stock alert quietly wrong.
 */
class BranchStockTest extends TestCase
{
    use RefreshDatabase;

    private Store $online;

    private Store $showroom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->online = $this->branch('Main Warehouse', fulfilsOnline: true, order: 1);
        $this->showroom = $this->branch('Uttara Showroom', fulfilsOnline: false, order: 2);
    }

    /** name, address and phone are all NOT NULL on stores. */
    private function branch(string $name, bool $fulfilsOnline, int $order): Store
    {
        return Store::create([
            'name' => $name,
            'city' => 'Dhaka',
            'address' => 'Test address',
            'phone' => '01711000000',
            'is_active' => true,
            'holds_stock' => true,
            'fulfils_online' => $fulfilsOnline,
            'sort_order' => $order,
        ]);
    }

    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'GPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id, 'name' => 'RTX 4090',
            'slug' => 'rtx-'.uniqid(), 'price' => 250000,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    private function stockAt(Product $product, Store $store): int
    {
        return (int) ProductStock::forUnit($product->id)
            ->where('store_id', $store->id)->value('quantity');
    }

    public function test_a_delivery_lands_at_a_named_branch(): void
    {
        $product = $this->product();

        app(StockService::class)->record($product, null, 10, StockMovement::PURCHASE, [
            'store_id' => $this->showroom->id,
        ]);

        $this->assertSame(10, $this->stockAt($product, $this->showroom));
        $this->assertSame(0, $this->stockAt($product, $this->online));
        // The shop-wide total is the sum of the branches.
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_the_total_is_always_the_sum_of_the_branches(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);

        $stock->record($product, null, 6, StockMovement::PURCHASE, ['store_id' => $this->online->id]);
        $stock->record($product->fresh(), null, 4, StockMovement::PURCHASE, ['store_id' => $this->showroom->id]);

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(
            10,
            (int) ProductStock::forUnit($product->id)->sum('quantity')
        );
    }

    /** A transfer moves units; it must never create or destroy any. */
    public function test_a_transfer_nets_to_zero(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->record($product, null, 10, StockMovement::PURCHASE, ['store_id' => $this->online->id]);

        $stock->transfer($product->fresh(), null, 4, $this->online->id, $this->showroom->id);

        $this->assertSame(6, $this->stockAt($product, $this->online));
        $this->assertSame(4, $this->stockAt($product, $this->showroom));
        $this->assertSame(10, $product->fresh()->stock_quantity, 'the total changed');

        $net = (int) StockMovement::where('product_id', $product->id)
            ->where('type', StockMovement::TRANSFER)->sum('quantity');
        $this->assertSame(0, $net);
    }

    public function test_a_branch_cannot_send_what_it_does_not_have(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->record($product, null, 3, StockMovement::PURCHASE, ['store_id' => $this->online->id]);

        try {
            $stock->transfer($product->fresh(), null, 5, $this->online->id, $this->showroom->id);
            $this->fail('a branch sent more than it held');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('RTX 4090', $e->getMessage());
        }

        // Nothing moved: the origin still has everything and the destination
        // was never credited.
        $this->assertSame(3, $this->stockAt($product, $this->online));
        $this->assertSame(0, $this->stockAt($product, $this->showroom));
        $this->assertSame(3, $product->fresh()->stock_quantity);
    }

    public function test_a_transfer_to_the_same_branch_is_refused(): void
    {
        $product = $this->product();
        app(StockService::class)->record($product, null, 5, StockMovement::PURCHASE, ['store_id' => $this->online->id]);

        $this->expectExceptionMessage('Choose two different branches');
        app(StockService::class)->transfer($product->fresh(), null, 2, $this->online->id, $this->online->id);
    }

    /**
     * The whole point of the split: the shop can hold plenty while the branch
     * that posts parcels holds none.
     */
    public function test_checkout_measures_the_branch_that_ships(): void
    {
        $product = $this->product();
        app(StockService::class)->record($product, null, 8, StockMovement::PURCHASE, [
            'store_id' => $this->showroom->id,
        ]);

        // Eight in the shop, none where orders are picked from.
        $this->assertSame(8, $product->fresh()->stock_quantity);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_transferring_stock_in_makes_it_sellable_online(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->record($product, null, 8, StockMovement::PURCHASE, ['store_id' => $this->showroom->id]);
        $stock->transfer($product->fresh(), null, 5, $this->showroom->id, $this->online->id);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->assertSame(3, $this->stockAt($product, $this->online), 'the sale came off the wrong branch');
        $this->assertSame(3, $this->stockAt($product, $this->showroom));
        $this->assertSame(6, $product->fresh()->stock_quantity);
    }

    public function test_a_cancelled_order_returns_units_to_the_branch_they_left(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->record($product, null, 5, StockMovement::PURCHASE, ['store_id' => $this->online->id]);
        $stock->record($product->fresh(), null, 5, StockMovement::PURCHASE, ['store_id' => $this->showroom->id]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 3]);
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->assertSame(2, $this->stockAt($product, $this->online));

        app(OrderService::class)->updateOrderStatus(Order::latest()->first(), 'cancelled');

        $this->assertSame(5, $this->stockAt($product, $this->online), 'units came back to the wrong branch');
        $this->assertSame(5, $this->stockAt($product, $this->showroom));
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_the_breakdown_reports_what_each_branch_holds(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->record($product, null, 7, StockMovement::PURCHASE, ['store_id' => $this->online->id]);
        $stock->record($product->fresh(), null, 2, StockMovement::PURCHASE, ['store_id' => $this->showroom->id]);

        $breakdown = collect($stock->branchBreakdown($product->fresh()))
            ->pluck('quantity', 'store');

        $this->assertSame(7, $breakdown['Main Warehouse']);
        $this->assertSame(2, $breakdown['Uttara Showroom']);
    }

    public function test_customers_are_shown_which_showroom_has_it(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->record($product, null, 4, StockMovement::PURCHASE, ['store_id' => $this->online->id]);
        $stock->record($product->fresh(), null, 2, StockMovement::PURCHASE, ['store_id' => $this->showroom->id]);

        $branches = $this->getJson("/api/products/{$product->id}/branches")
            ->assertStatus(200)
            ->json('data.branches');

        // The online branch is the warehouse the site already sells from, not
        // somewhere to go and look at one.
        $this->assertSame(['Uttara Showroom'], collect($branches)->pluck('store')->all());
        $this->assertTrue($branches[0]['available']);
    }

    /** A showroom count is stale the moment someone walks in with one. */
    public function test_the_customer_is_not_given_an_exact_showroom_count(): void
    {
        $product = $this->product();
        app(StockService::class)->record($product, null, 9, StockMovement::PURCHASE, [
            'store_id' => $this->showroom->id,
        ]);

        $branches = $this->getJson("/api/products/{$product->id}/branches")->json('data.branches');

        $this->assertArrayNotHasKey('quantity', $branches[0]);
        $this->assertTrue($branches[0]['available']);
    }

    public function test_a_branch_with_none_left_is_not_listed(): void
    {
        $product = $this->product();
        app(StockService::class)->record($product, null, 5, StockMovement::PURCHASE, [
            'store_id' => $this->online->id,
        ]);

        $branches = $this->getJson("/api/products/{$product->id}/branches")->json('data.branches');

        $this->assertSame([], $branches);
    }

    public function test_an_admin_can_move_stock_over_http(): void
    {
        $product = $this->product();
        app(StockService::class)->record($product, null, 6, StockMovement::PURCHASE, [
            'store_id' => $this->online->id,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/stock/transfer', [
                'product_id' => $product->id,
                'quantity' => 2,
                'from_store_id' => $this->online->id,
                'to_store_id' => $this->showroom->id,
            ])->assertStatus(200);

        $this->assertSame(4, $this->stockAt($product, $this->online));
        $this->assertSame(2, $this->stockAt($product, $this->showroom));
    }

    public function test_a_customer_cannot_move_stock(): void
    {
        $product = $this->product();
        app(StockService::class)->record($product, null, 6, StockMovement::PURCHASE, [
            'store_id' => $this->online->id,
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/api/admin/stock/transfer', [
                'product_id' => $product->id,
                'quantity' => 2,
                'from_store_id' => $this->online->id,
                'to_store_id' => $this->showroom->id,
            ]);

        $this->assertContains($response->status(), [302, 403]);
        $this->assertSame(6, $this->stockAt($product, $this->online));
    }

    public function test_a_shop_with_no_configured_branch_still_sells(): void
    {
        // A misconfigured shop must not reject every checkout.
        Store::query()->update(['fulfils_online' => false, 'holds_stock' => false]);

        $product = $this->product();
        $product->update(['stock_quantity' => 5]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);
    }
}
