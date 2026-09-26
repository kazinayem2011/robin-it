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
     * Held only in a showroom: it used to show "In Stock", go into the cart,
     * and be refused at checkout because the online branch had none. An order
     * now takes from whichever branch has it.
     */
    public function test_an_order_takes_from_another_branch_when_the_default_has_none(): void
    {
        $product = $this->product();
        app(StockService::class)->record($product, null, 8, StockMovement::PURCHASE, [
            'store_id' => $this->showroom->id,
        ]);

        $this->checkout($product, 2)->assertStatus(201);

        $this->assertSame(6, $this->stockAt($product, $this->showroom));
        $this->assertSame(0, $this->stockAt($product, $this->online));
    }

    /* The default branch first, while it has the units. */
    public function test_the_default_branch_is_used_first(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->record($product, null, 5, StockMovement::PURCHASE, ['store_id' => $this->online->id]);
        $stock->record($product->fresh(), null, 5, StockMovement::PURCHASE, ['store_id' => $this->showroom->id]);

        $this->checkout($product, 2)->assertStatus(201);

        $this->assertSame(3, $this->stockAt($product, $this->online));
        $this->assertSame(5, $this->stockAt($product, $this->showroom));
    }

    /* More than any one branch has, fewer than the shop has: both give. */
    public function test_a_line_can_come_from_two_branches(): void
    {
        $product = $this->product();
        $stock = app(StockService::class);
        $stock->record($product, null, 2, StockMovement::PURCHASE, ['store_id' => $this->online->id]);
        $stock->record($product->fresh(), null, 3, StockMovement::PURCHASE, ['store_id' => $this->showroom->id]);

        $this->checkout($product, 4)->assertStatus(201);

        $this->assertSame(0, $this->stockAt($product, $this->online));
        $this->assertSame(1, $this->stockAt($product, $this->showroom));

        $order = Order::latest('id')->first();
        $this->assertSame(
            [$this->online->id => 2, $this->showroom->id => 2],
            app(StockService::class)->branchesHolding($order)[$product->id.':-'],
        );

        // Cancelled: each part goes back where it came from.
        app(OrderService::class)->updateOrderStatus($order, 'cancelled');

        $this->assertSame(2, $this->stockAt($product, $this->online));
        $this->assertSame(3, $this->stockAt($product, $this->showroom));
    }

    /*
     * More than every branch holds: what there is is taken, and the rest is
     * owed at the default branch until the next delivery lands there.
     */
    public function test_more_than_every_branch_holds_is_taken_and_owed_at_the_default(): void
    {
        $product = $this->product();
        app(StockService::class)->record($product, null, 3, StockMovement::PURCHASE, ['store_id' => $this->showroom->id]);

        $this->checkout($product, 4)->assertStatus(201);

        $this->assertSame(0, $this->stockAt($product, $this->showroom));
        $this->assertSame(-1, $this->stockAt($product, $this->online), 'one owed at the default branch');
        $this->assertTrue(Order::latest('id')->first()->items()->first()->waiting_for_stock);
    }

    /* Nothing anywhere: Sold Out. */
    public function test_with_nothing_in_any_branch_it_is_refused(): void
    {
        $product = $this->product();

        $this->checkout($product, 1)->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    private function checkout(Product $product, int $quantity)
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => $quantity]);

        return $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ]);
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
