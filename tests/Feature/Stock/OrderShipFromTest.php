<?php

namespace Tests\Feature\Stock;

use App\Exceptions\StorefrontException;
use App\Models\Category;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\OrderEditService;
use App\Services\OrderService;
use App\Services\PurchaseOrderService;
use App\Services\StockService;
use App\Services\StockTakeService;
use App\Support\PreorderLedger;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branch an order ships from, chosen by the admin.
 *
 * The client's flow: an order comes in, the admin picks which branch sends
 * it, the stock comes off there — and goes back there on a cancellation or a
 * return. Until now an order had no branch at all: it drew on one fixed
 * online branch, and was put back wherever that happened to be later.
 */
class OrderShipFromTest extends TestCase
{
    use RefreshDatabase;

    private Store $khulna;

    private Store $dhaka;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->khulna = $this->branch('Khulna', online: true, order: 1);
        $this->dhaka = $this->branch('Dhaka', online: false, order: 2);
        $this->admin = User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);
    }

    private function branch(string $name, bool $online, int $order): Store
    {
        return Store::create([
            'name' => $name, 'city' => $name, 'address' => 'Test address', 'phone' => '01711000000',
            'is_active' => true, 'holds_stock' => true, 'fulfils_online' => $online, 'sort_order' => $order,
        ]);
    }

    private function product(int $khulna, int $dhaka): Product
    {
        $category = Category::firstOrCreate(['slug' => 'laptop'], ['name' => 'Laptop', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'ASUS Vivobook', 'slug' => 'asus-'.uniqid(),
            'price' => 70000, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        $stock = app(StockService::class);

        if ($khulna) {
            $stock->record($product, null, $khulna, StockMovement::PURCHASE, ['store_id' => $this->khulna->id]);
        }
        if ($dhaka) {
            $stock->record($product->fresh(), null, $dhaka, StockMovement::PURCHASE, ['store_id' => $this->dhaka->id]);
        }

        return $product->fresh();
    }

    private function at(Product $product, Store $store): int
    {
        return (int) ProductStock::forUnit($product->id)->where('store_id', $store->id)->value('quantity');
    }

    private function order(Product $product, int $quantity = 1): Order
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => $quantity]);
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678', 'street_address' => 'House 1', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::latest('id')->first();
    }

    public function test_the_admin_can_move_an_order_to_another_branch(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);
        $order = $this->order($product, 2);

        $this->assertSame(1, $this->at($product, $this->khulna));

        $this->actingAs($this->admin)
            ->putJson("/api/admin/orders/{$order->id}/ship-from", ['store_id' => $this->dhaka->id])
            ->assertOk()
            ->assertJsonPath('data.ship_from.0.name', 'Dhaka')
            ->assertJsonPath('data.ship_from.0.units', 2);

        $this->assertSame(3, $this->at($product, $this->khulna), 'Khulna should have its units back');
        $this->assertSame(1, $this->at($product, $this->dhaka));
        $this->assertSame(4, $product->fresh()->stock_quantity, 'the shop-wide total must not change');
    }

    /* Then cancelled: back to Dhaka, where the order now draws from. */
    public function test_a_cancellation_after_a_move_goes_back_to_the_new_branch(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);
        $order = $this->order($product, 2);
        app(StockService::class)->moveOrderTo($order, $this->dhaka->id);

        app(OrderService::class)->updateOrderStatus($order->fresh(), 'cancelled');

        $this->assertSame(3, $this->at($product, $this->khulna));
        $this->assertSame(3, $this->at($product, $this->dhaka));
    }

    public function test_a_branch_without_enough_is_refused_and_nothing_moves(): void
    {
        $product = $this->product(khulna: 3, dhaka: 1);
        $order = $this->order($product, 2);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/orders/{$order->id}/ship-from", ['store_id' => $this->dhaka->id])
            ->assertStatus(422);

        $this->assertSame(1, $this->at($product, $this->khulna));
        $this->assertSame(1, $this->at($product, $this->dhaka));
    }

    public function test_the_options_say_which_branches_have_it_all(): void
    {
        $product = $this->product(khulna: 3, dhaka: 1);
        $order = $this->order($product, 2);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/orders/{$order->id}/ship-from")
            ->assertOk()
            ->assertJsonPath('data.current.0.name', 'Khulna')
            ->assertJsonPath('data.can_change', true);

        $branches = collect($response->json('data.branches'))->keyBy('name');
        $this->assertTrue($branches['Khulna']['covers']);
        $this->assertFalse($branches['Dhaka']['covers']);
        $this->assertSame(1, $branches['Dhaka']['short'][0]['available']);
    }

    /*
     * A pre-order sold at Khulna leaves Khulna below zero until the delivery
     * lands. The branch the order is at still covers it; it said "short 0 of 1".
     */
    public function test_the_branch_holding_a_pre_order_is_still_the_one_it_ships_from(): void
    {
        $product = $this->product(khulna: 0, dhaka: 0);
        $product->update(['allow_preorder' => true]);
        $order = $this->order($product->fresh());

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/orders/{$order->id}/ship-from")
            ->assertOk();

        $khulna = collect($response->json('data.branches'))->firstWhere('name', 'Khulna');
        $this->assertTrue($khulna['covers']);
        $this->assertTrue($khulna['is_current']);
    }

    /* Branch by item: two branches between them cover what neither can alone. */
    public function test_a_line_can_be_split_between_branches_by_hand(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);
        $order = $this->order($product, 3);
        $item = $order->items()->first();

        $this->assertSame(0, $this->at($product, $this->khulna));

        $this->actingAs($this->admin)
            ->putJson("/api/admin/orders/{$order->id}/ship-from", ['lines' => [[
                'order_item_id' => $item->id,
                'stores' => [$this->khulna->id => 1, $this->dhaka->id => 2],
            ]]])
            ->assertOk();

        $this->assertSame(2, $this->at($product, $this->khulna));
        $this->assertSame(1, $this->at($product, $this->dhaka));
        $this->assertSame(
            [$this->khulna->id => 1, $this->dhaka->id => 2],
            app(StockService::class)->branchesHolding($order)[$product->id.':-'],
        );

        // Cancelled: each branch gets back exactly its share.
        app(OrderService::class)->updateOrderStatus($order->fresh(), 'cancelled');
        $this->assertSame(3, $this->at($product, $this->khulna));
        $this->assertSame(3, $this->at($product, $this->dhaka));
    }

    public function test_a_split_must_add_up_to_the_line(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);
        $order = $this->order($product, 2);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/orders/{$order->id}/ship-from", ['lines' => [[
                'order_item_id' => $order->items()->first()->id,
                'stores' => [$this->dhaka->id => 1],
            ]]])
            ->assertStatus(422);

        $this->assertSame(1, $this->at($product, $this->khulna));
        $this->assertSame(3, $this->at($product, $this->dhaka));
    }

    /* A "waiting for stock" line filled from a branch that has it. */
    /*
     * "Waiting for stock" says what is true now. It was fixed at the moment
     * of sale, so a line already filled went on saying it was waiting.
     */
    public function test_the_wait_ends_when_a_delivery_covers_it(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);
        $this->assertTrue($order->items()->first()->waiting_for_stock);

        app(StockService::class)->record($product->fresh(), null, 3, StockMovement::PURCHASE, ['store_id' => $this->khulna->id]);
        app(PreorderLedger::class)->forget($order->id);

        $item = $order->items()->first();
        $this->assertFalse($item->waiting_for_stock);
        $this->assertFalse($item->was_preordered, 'no longer ships later');
    }

    public function test_the_wait_ends_when_the_admin_fills_it_from_another_branch(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);
        app(StockService::class)->record($product->fresh(), null, 2, StockMovement::PURCHASE, ['store_id' => $this->dhaka->id]);

        app(StockService::class)->allocateOrderLine($order, $product->fresh(), null, [
            $this->khulna->id => 1, $this->dhaka->id => 1,
        ]);

        $this->assertFalse($order->items()->first()->waiting_for_stock);
    }

    public function test_owed_units_can_be_filled_from_another_branch(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);

        $this->assertSame(-1, $this->at($product, $this->khulna), 'one owed at the default');

        // A delivery lands at Dhaka; the admin sends the owed unit from there.
        app(StockService::class)->record($product->fresh(), null, 2, StockMovement::PURCHASE, ['store_id' => $this->dhaka->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/orders/{$order->id}/ship-from", ['lines' => [[
                'order_item_id' => $order->items()->first()->id,
                'stores' => [$this->khulna->id => 1, $this->dhaka->id => 1],
            ]]])
            ->assertOk();

        $this->assertSame(0, $this->at($product, $this->khulna), 'nothing owed any more');
        $this->assertSame(1, $this->at($product, $this->dhaka));
    }

    public function test_the_options_describe_each_line(): void
    {
        $product = $this->product(khulna: 3, dhaka: 1);
        $order = $this->order($product, 2);

        $line = $this->actingAs($this->admin)
            ->getJson("/api/admin/orders/{$order->id}/ship-from")
            ->assertOk()
            ->json('data.lines.0');

        $this->assertSame(2, $line['units']);
        $this->assertSame([['id' => $this->khulna->id, 'units' => 2]], $line['current']);
        $available = collect($line['branches'])->pluck('available', 'name');
        $this->assertSame(3, $available['Khulna'], 'its stock plus what the line holds there');
        $this->assertSame(1, $available['Dhaka']);
    }

    /* A branch that owes a unit does not offer it: 2 there, not 3. */
    public function test_an_owed_unit_is_not_counted_as_available(): void
    {
        $product = $this->product(khulna: 2, dhaka: 0);
        $order = $this->order($product, 3);

        $this->assertSame(-1, $this->at($product, $this->khulna));

        $line = $this->actingAs($this->admin)
            ->getJson("/api/admin/orders/{$order->id}/ship-from")
            ->json('data.lines.0');

        $this->assertSame(2, collect($line['branches'])->firstWhere('name', 'Khulna')['available']);
    }

    public function test_the_branch_cannot_change_once_dispatched(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);
        $order = $this->order($product);
        $order->update(['status' => 'shipped']);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/orders/{$order->id}/ship-from", ['store_id' => $this->dhaka->id])
            ->assertStatus(422);

        $this->assertSame(2, $this->at($product, $this->khulna));
    }

    public function test_the_orders_page_says_where_each_order_ships_from(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);
        $this->order($product);

        $this->actingAs($this->admin)->get('/admin/orders')
            ->assertInertia(fn ($page) => $page
                ->where('orders.data.0.ship_from.0.name', 'Khulna')
                ->where('orders.data.0.can_change_ship_from', true)
                ->where('branches.0.name', 'Khulna'));
    }

    /* A counter sale at the Dhaka showroom comes off Dhaka's shelf. */
    public function test_a_counter_sale_ships_from_the_branch_it_was_made_at(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);

        $this->actingAs($this->admin)->postJson('/api/admin/orders', [
            'name' => 'Walk-in', 'phone' => '01712345678', 'street_address' => 'Counter', 'city' => 'Dhaka',
            'store_id' => $this->dhaka->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk();

        $this->assertSame(3, $this->at($product, $this->khulna));
        $this->assertSame(2, $this->at($product, $this->dhaka));
    }

    /* Back to the branch it left. */
    public function test_a_return_goes_back_to_the_branch_it_left(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);
        $order = $this->order($product, 2);
        $order->update(['status' => 'delivered']);

        app(OrderService::class)->returnOrder($order->fresh(), [
            ['order_item_id' => $order->items()->first()->id, 'resellable' => 2],
        ]);

        $this->assertSame(3, $this->at($product, $this->khulna));
        $this->assertSame(3, $this->at($product, $this->dhaka));
    }

    /* Damaged: back to the branch, then written off from the same one. */
    public function test_a_damaged_return_is_written_off_at_its_branch(): void
    {
        $product = $this->product(khulna: 3, dhaka: 3);
        $order = $this->order($product, 1);
        $order->update(['status' => 'delivered']);

        app(OrderService::class)->returnOrder($order->fresh(), [[
            'order_item_id' => $order->items()->first()->id, 'damaged' => 1,
        ]]);

        $this->assertSame(2, $this->at($product, $this->khulna));
        $this->assertSame(
            $this->khulna->id,
            (int) StockMovement::where('type', StockMovement::WRITE_OFF)->value('store_id'),
        );
    }

    // --- Nothing leaves while it is still owed --------------------------------

    /*
     * The unit is owed at the online branch; a delivery received into another
     * one leaves it just as short. "Not in stock yet" alone read as wrong to
     * whoever had just received it, so the refusal names the branch.
     */
    public function test_the_refusal_names_the_branch_the_unit_is_owed_at(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);

        // The missing one lands at Dhaka, not Khulna, where it is owed.
        app(StockService::class)->adjust($product, null, 1, 'other', 'QA', null, $this->dhaka->id);

        try {
            app(OrderService::class)->updateOrderStatus($order->fresh(), 'shipped');
            $this->fail('A waiting order was marked shipped.');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('ASUS Vivobook', $e->getMessage());
            $this->assertStringContainsString('owed at Khulna', $e->getMessage());
        }
    }

    /*
     * An order waiting for stock has nothing on the shelf to put in the box.
     * It could be marked shipped or delivered anyway, telling the customer it
     * was on its way.
     */
    public function test_an_order_waiting_for_stock_cannot_be_shipped_or_delivered(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);
        $orders = app(OrderService::class);

        foreach (['shipped', 'delivered'] as $status) {
            try {
                $orders->updateOrderStatus($order->fresh(), $status);
                $this->fail("A waiting order was marked {$status}.");
            } catch (StorefrontException $e) {
                $this->assertStringContainsString('Not in stock yet: ASUS Vivobook', $e->getMessage());
            }
        }

        $this->assertSame('pending', $order->fresh()->status);

        // Processing is fine: the shop is working on it.
        $orders->updateOrderStatus($order->fresh(), 'processing');
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_once_the_delivery_covers_it_the_order_can_ship(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);

        app(StockService::class)->record($product->fresh(), null, 1, StockMovement::PURCHASE, ['store_id' => $this->khulna->id]);

        app(OrderService::class)->updateOrderStatus($order->fresh(), 'delivered');
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_filling_it_from_another_branch_lets_it_ship(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);
        app(StockService::class)->record($product->fresh(), null, 2, StockMovement::PURCHASE, ['store_id' => $this->dhaka->id]);
        app(StockService::class)->allocateOrderLine($order, $product->fresh(), null, [
            $this->khulna->id => 1, $this->dhaka->id => 1,
        ]);

        app(OrderService::class)->updateOrderStatus($order->fresh(), 'shipped');
        $this->assertSame('shipped', $order->fresh()->status);
    }

    /* A pre-order waits for its delivery the same way, then ships. */
    public function test_a_pre_order_ships_only_once_its_delivery_lands(): void
    {
        $product = $this->product(khulna: 0, dhaka: 0);
        $product->update(['allow_preorder' => true]);
        $order = $this->order($product->fresh());

        try {
            app(OrderService::class)->updateOrderStatus($order->fresh(), 'shipped');
            $this->fail('A pre-order left before its stock arrived.');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('Not in stock yet', $e->getMessage());
        }

        app(StockService::class)->record($product->fresh(), null, 1, StockMovement::PURCHASE, ['store_id' => $this->khulna->id]);

        app(OrderService::class)->updateOrderStatus($order->fresh(), 'shipped');
        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_a_waiting_order_cannot_be_dispatched_either(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);
        $courier = Courier::create(['name' => 'Steadfast', 'slug' => 'steadfast-owed', 'is_active' => true]);

        $this->expectExceptionMessage('Not in stock yet');
        app(OrderService::class)->dispatchOrder($order->fresh(), $courier, 'TRK-1');
    }

    public function test_the_admin_is_told_why_through_the_status_endpoint(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Not in stock yet'));

        $this->assertSame('pending', $order->fresh()->status);
    }

    // --- A branch that owes units -------------------------------------------------

    /*
     * Khulna at -1: one unit sold past stock. Its shelf is empty, and counting
     * it empty used to "find" a unit against -1 and end the customer's wait.
     */
    public function test_counting_an_empty_shelf_does_not_invent_the_owed_units(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);
        $this->assertSame(-1, $this->at($product, $this->khulna));

        $sheet = app(StockTakeService::class)->sheetFor($this->khulna)->firstWhere('product_id', $product->id);
        $this->assertSame(0, $sheet['system_quantity']);
        $this->assertSame(1, $sheet['owed']);

        $this->actingAs($this->admin)->postJson('/api/admin/stock/count', [
            'store_id' => $this->khulna->id,
            'lines' => [['product_id' => $product->id, 'counted_quantity' => 0]],
        ])->assertSuccessful();

        $this->assertSame(-1, $this->at($product->fresh(), $this->khulna), 'still owed');
        app(PreorderLedger::class)->forget($order->id);
        $this->assertTrue($order->items()->first()->waiting_for_stock);
    }

    /* Found on the shelf: those units go to the waiting customer first. */
    public function test_units_found_by_a_count_fill_what_is_owed_first(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $order = $this->order($product, 2);

        $this->actingAs($this->admin)->postJson('/api/admin/stock/count', [
            'store_id' => $this->khulna->id,
            'lines' => [['product_id' => $product->id, 'counted_quantity' => 3]],
        ])->assertSuccessful();

        $this->assertSame(2, $this->at($product->fresh(), $this->khulna));
        app(PreorderLedger::class)->forget($order->id);
        $this->assertFalse($order->items()->first()->waiting_for_stock);
    }

    public function test_a_branch_owing_units_cannot_be_closed_or_removed(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $this->order($product, 2);

        $this->actingAs($this->admin)->putJson("/api/admin/stores/{$this->khulna->id}", [
            'name' => 'Khulna', 'city' => 'Khulna', 'address' => 'Test address',
            'phone' => '01711000000', 'is_active' => false,
            'branch_type' => 'Express Outlet', 'opening_hours' => '10am - 8pm',
        ])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'owes 1 units to customers waiting for a delivery'));

        $this->actingAs($this->admin)->deleteJson("/api/admin/stores/{$this->khulna->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'receive the delivery or ship those orders from another branch'));

        $this->assertTrue((bool) $this->khulna->fresh()->is_active);
    }

    // --- The dashboard says what needs doing ----------------------------------------

    private function card(string $label): ?int
    {
        $cards = $this->actingAs($this->admin)->get('/admin/dashboard')->viewData('page')['props']['attention'];

        return collect($cards)->firstWhere('label', $label)['count'] ?? null;
    }

    public function test_the_dashboard_counts_orders_waiting_for_stock_until_it_arrives(): void
    {
        $product = $this->product(khulna: 1, dhaka: 0);
        $this->order($product, 2);
        $this->assertSame(1, $this->card('Waiting for stock'));

        app(StockService::class)->record($product->fresh(), null, 1, StockMovement::PURCHASE, ['store_id' => $this->khulna->id]);
        $this->assertSame(0, $this->card('Waiting for stock'));
    }

    public function test_the_dashboard_counts_purchase_orders_past_their_date(): void
    {
        $product = $this->product(khulna: 0, dhaka: 0);
        $supplier = Supplier::create(['name' => 'Star Tech']);
        $orders = app(PurchaseOrderService::class);
        $orders->save(null, $supplier, $this->admin, [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 10]], ['expected_on' => now()->subDays(3)->toDateString()]);
        $orders->save(null, $supplier, $this->admin, [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 10]], ['expected_on' => now()->addDays(3)->toDateString()]);

        $this->assertSame(1, $this->card('Purchase orders overdue'));
    }

    /* A listing never bought in is not "low"; one that ran down is. */
    public function test_low_stock_counts_only_what_the_shop_stocks(): void
    {
        $stocked = $this->product(khulna: 2, dhaka: 0);
        $this->assertSame(1, $this->card('Low stock'));

        Product::create(['category_id' => $stocked->category_id, 'name' => 'Never bought in', 'slug' => 'never', 'price' => 10, 'stock_quantity' => 0, 'is_active' => true]);
        $this->assertSame(1, $this->card('Low stock'));
    }

    /*
     * Edited down while a unit is owed: the debt clears, not a real shelf.
     * Two came from Dhaka and the third was owed at Khulna; edited to two,
     * the unit went back onto Dhaka's shelf and left Khulna owing, so the
     * order stayed "waiting for stock" and was refused at dispatch with the
     * units it needed sitting in Dhaka. Found on live.
     */
    public function test_an_order_edited_down_clears_its_debt_first(): void
    {
        $product = $this->product(khulna: 0, dhaka: 2);
        $order = $this->order($product, 3);
        $this->assertSame(-1, $this->at($product, $this->khulna));
        $this->assertSame(0, $this->at($product, $this->dhaka));

        $item = $order->items()->first();
        app(OrderEditService::class)->apply($order->fresh(), $this->admin, [
            ['order_item_id' => $item->id, 'quantity' => 2],
        ], 'Customer takes two');

        $this->assertSame(0, $this->at($product, $this->khulna), 'the owed unit should be the one given back');
        $this->assertSame(0, $this->at($product, $this->dhaka));

        app(OrderService::class)->updateOrderStatus($order->fresh(), 'shipped');
        $this->assertSame('shipped', $order->fresh()->status);
    }
}
