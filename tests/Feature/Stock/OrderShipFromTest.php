<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
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
}
