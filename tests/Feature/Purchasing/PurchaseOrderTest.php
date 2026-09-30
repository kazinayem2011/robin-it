<?php

namespace Tests\Feature\Purchasing;

use App\Exceptions\StorefrontException;
use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asking a supplier for stock, and checking what arrives against what was asked.
 *
 * Receiving already worked; ordering did not exist. Between placing an order
 * and its arrival the shop held no record of it — so nobody could answer "when
 * are those back in", nobody could tell a supplier who shipped fifteen of
 * twenty that they still owed five, and an invoice had nothing to check against.
 */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderService $orders;

    private Supplier $supplier;

    private Product $product;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = app(PurchaseOrderService::class);
        $this->buyer = User::factory()->create(['role' => 'admin', 'name' => 'Nayem']);

        $this->supplier = Supplier::create([
            'name' => 'Smart Technologies', 'phone' => '01711000000', 'is_active' => true,
        ]);

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'GPU', 'slug' => 'gpu', 'is_active' => true])->id,
            'name' => 'RTX 4090', 'slug' => 'rtx-4090-po',
            'price' => 245000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    private function draft(int $quantity = 20, ?float $cost = 200000): PurchaseOrder
    {
        return $this->orders->save(null, $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    /*
     * The receive window asks for serials from what the order says. It was
     * sent each product with only its id and name, so a product with a
     * warranty read as not needing them — and the server then refused the
     * delivery for the serials it had not asked for.
     */
    public function test_the_order_says_which_lines_need_serials(): void
    {
        $this->product->update(['warranty_months' => 12]);
        $order = $this->draft(3);

        $line = $this->actingAs($this->buyer)
            ->getJson("/api/admin/purchase-orders/{$order->id}")
            ->assertOk()
            ->json('data.order.items.0') ?? $this->actingAs($this->buyer)
            ->getJson("/api/admin/purchase-orders/{$order->id}")->json('data.items.0');

        $this->assertTrue($line['needs_serials']);
    }

    // --- writing one -------------------------------------------------------

    public function test_an_order_records_what_was_asked_for(): void
    {
        $order = $this->draft();

        // Open from the moment it is saved: there is no draft stage.
        $this->assertSame(PurchaseOrder::SENT, $order->status);
        $this->assertSame('Ordered', $order->status_label);
        $this->assertNotNull($order->sent_at);
        $this->assertStringStartsWith('PO-', $order->reference);
        $this->assertSame(20, $order->total_quantity);
        $this->assertSame(4000000.0, $order->total_cost);
        $this->assertSame('Nayem', $order->ordered_by_name);
        $this->assertSame('Smart Technologies', $order->supplier_name);
    }

    /** Ordering must not create stock. Nothing has arrived yet. */
    public function test_placing_an_order_moves_no_stock(): void
    {
        $this->draft();

        $this->assertSame(0, $this->product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_an_order_with_no_lines_is_refused(): void
    {
        $this->expectExceptionMessage('Add at least one product with a quantity');
        $this->orders->save(null, $this->supplier, $this->buyer, []);
    }

    // --- changing it after it is placed ----------------------------------------

    /** Suppliers change quantities and prices; the order follows. */
    public function test_an_open_order_can_be_changed(): void
    {
        $order = $this->draft(20, 200000);

        $order = $this->orders->save($order, $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 12, 'unit_cost' => 195000],
        ]);

        $this->assertSame(12, $order->total_quantity);
        $this->assertSame(195000.0, (float) $order->items->first()->unit_cost);
        $this->assertSame(PurchaseOrder::SENT, $order->status);
    }

    /** What has arrived stays arrived: a line keeps its count through an edit. */
    public function test_editing_a_part_delivered_order_keeps_what_arrived(): void
    {
        $order = $this->draft(20);
        $item = $order->items()->first();
        $this->orders->receive($order, $this->buyer, [['purchase_order_item_id' => $item->id, 'quantity' => 8]]);

        $order = $this->orders->save($order->fresh(), $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 15, 'unit_cost' => 200000],
        ]);

        $line = $order->items->first();
        $this->assertSame($item->id, $line->id, 'the line is changed in place, not replaced');
        $this->assertSame(8, $line->quantity_received);
        $this->assertSame(7, $order->outstanding);
        $this->assertSame(PurchaseOrder::PARTIAL, $order->status);
    }

    public function test_a_quantity_cannot_go_below_what_arrived(): void
    {
        $order = $this->draft(20);
        $this->orders->receive($order, $this->buyer, [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 8]]);

        $this->expectExceptionMessage('8 have already arrived, so the quantity cannot go below 8.');
        $this->orders->save($order->fresh(), $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 200000],
        ]);
    }

    public function test_a_line_that_arrived_cannot_be_taken_off(): void
    {
        $other = Product::create([
            'name' => 'Spare Mouse', 'slug' => 'spare-mouse', 'category_id' => $this->product->category_id,
            'price' => 900, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $order = $this->orders->save(null, $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 200000],
            ['product_id' => $other->id, 'quantity' => 5, 'unit_cost' => 500],
        ]);
        $laptopLine = $order->items->firstWhere('product_id', $this->product->id);
        $this->orders->receive($order, $this->buyer, [['purchase_order_item_id' => $laptopLine->id, 'quantity' => 2]]);

        // Dropping the mouse (nothing arrived) is fine.
        $order = $this->orders->save($order->fresh(), $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 200000],
        ]);
        $this->assertCount(1, $order->items);

        // Dropping the laptop (2 arrived) is not.
        $this->expectExceptionMessage('cannot be taken off the order');
        $this->orders->save($order, $this->supplier, $this->buyer, [
            ['product_id' => $other->id, 'quantity' => 5, 'unit_cost' => 500],
        ]);
    }

    /** Lowering a line to what has arrived means nothing more is coming. */
    public function test_lowering_to_what_arrived_completes_the_order(): void
    {
        $order = $this->draft(20);
        $this->orders->receive($order, $this->buyer, [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 8]]);

        $order = $this->orders->save($order->fresh(), $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 8, 'unit_cost' => 200000],
        ]);

        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
        $this->assertSame(0, $order->outstanding);
    }

    public function test_the_supplier_cannot_change_once_something_arrived(): void
    {
        $order = $this->draft(20);
        $this->orders->receive($order, $this->buyer, [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 1]]);
        $other = Supplier::create(['name' => 'Another Supplier']);

        $this->expectExceptionMessage('the supplier cannot be changed');
        $this->orders->save($order->fresh(), $other, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 200000],
        ]);
    }

    public function test_a_delivered_or_cancelled_order_cannot_be_changed(): void
    {
        $order = $this->draft(2);
        $this->orders->receive($order, $this->buyer, [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 2]]);

        try {
            $this->orders->save($order->fresh(), $this->supplier, $this->buyer, [
                ['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 1],
            ]);
            $this->fail('A delivered order was changed.');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('Everything on this order has arrived', $e->getMessage());
        }

        $cancelled = $this->orders->cancel($this->draft(4));
        $this->expectExceptionMessage('This order was cancelled');
        $this->orders->save($cancelled, $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 1],
        ]);
    }

    /** An old draft left from before is opened, not stranded. */
    public function test_an_old_draft_can_still_be_received(): void
    {
        $order = $this->draft(5);
        $order->update(['status' => PurchaseOrder::DRAFT]);

        $this->orders->receive($order, $this->buyer, [['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 5]]);

        $this->assertSame(PurchaseOrder::RECEIVED, $order->fresh()->status);
    }

    // --- receiving against it ---------------------------------------------

    public function test_receiving_the_lot_closes_the_order_and_moves_the_stock(): void
    {
        $order = $this->orders->send($this->draft());
        $item = $order->items()->first();

        $receipt = $this->orders->receive($order, $this->buyer, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 20],
        ]);

        $order->refresh()->load('items');

        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
        $this->assertSame(0, $order->outstanding);
        $this->assertSame(20, $this->product->fresh()->stock_quantity);

        // The delivery is tied back to what it was against.
        $this->assertSame($order->id, $receipt->purchase_order_id);
        // And the quoted cost carried through, so cost of goods is right
        // without anybody retyping it.
        $this->assertSame(200000.0, (float) $receipt->items->first()->unit_cost);
    }

    /**
     * The reason the record exists: fifteen against an order for twenty leaves
     * five outstanding rather than silently closing.
     */
    public function test_a_short_shipment_leaves_the_rest_outstanding(): void
    {
        $order = $this->orders->send($this->draft());
        $item = $order->items()->first();

        $this->orders->receive($order, $this->buyer, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 15],
        ]);

        $order->refresh()->load('items');

        $this->assertSame(PurchaseOrder::PARTIAL, $order->status);
        $this->assertSame(5, $order->outstanding);
        $this->assertSame(15, $this->product->fresh()->stock_quantity);
    }

    public function test_the_rest_arriving_later_completes_it(): void
    {
        $order = $this->orders->send($this->draft());
        $item = $order->items()->first();

        $this->orders->receive($order, $this->buyer, [['purchase_order_item_id' => $item->id, 'quantity' => 15]]);
        $this->orders->receive($order->fresh(), $this->buyer, [['purchase_order_item_id' => $item->id, 'quantity' => 5]]);

        $order->refresh()->load('items');

        $this->assertSame(PurchaseOrder::RECEIVED, $order->status);
        $this->assertSame(20, $this->product->fresh()->stock_quantity);
        $this->assertSame(2, $order->receipts()->count());
    }

    /**
     * A supplier sending extra is a conversation, not something to absorb
     * quietly — booking it in here would leave the order over-delivered and
     * the invoice disagreeing with the paperwork.
     */
    public function test_more_than_was_ordered_is_refused_with_the_arithmetic(): void
    {
        $order = $this->orders->send($this->draft());
        $item = $order->items()->first();

        $this->expectExceptionMessage('only 20 still to come on this order, and 25 were entered');
        $this->orders->receive($order, $this->buyer, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 25],
        ]);
    }

    /** No Send step: a new order is received against straight away. */
    public function test_a_new_order_can_be_received_straight_away(): void
    {
        $order = $this->draft(3);

        $this->orders->receive($order, $this->buyer, [
            ['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 3],
        ]);

        $this->assertSame(3, $this->product->fresh()->stock_quantity);
    }

    // --- what is on its way -------------------------------------------------

    /**
     * The question a buyer asks before ordering more, and a salesperson asks
     * when the shelf has run out.
     */
    public function test_what_is_on_order_counts_only_what_is_still_coming(): void
    {
        $key = $this->product->id.':';

        $this->draft(20);                            // open from saving
        $this->assertSame(20, $this->orders->onOrder()[$key]);

        $sent = $this->draft(30);
        $this->assertSame(50, $this->orders->onOrder()[$key]);

        $this->orders->receive($sent, $this->buyer, [
            ['purchase_order_item_id' => $sent->items()->first()->id, 'quantity' => 12],
        ]);

        $this->assertSame(38, $this->orders->onOrder()[$key]);
    }

    public function test_a_cancelled_order_is_no_longer_on_its_way(): void
    {
        $order = $this->orders->send($this->draft(30));
        $this->assertNotEmpty($this->orders->onOrder());

        $this->orders->cancel($order);

        $this->assertSame([], $this->orders->onOrder());
        $this->assertSame(0, $order->fresh()->load('items')->outstanding);
    }

    /**
     * Cancelling is a statement about the order, not about how much arrived.
     * A late delivery must not quietly reopen it.
     */
    public function test_a_cancelled_order_stays_cancelled(): void
    {
        $order = $this->orders->send($this->draft());
        $this->orders->receive($order, $this->buyer, [
            ['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 20],
        ]);

        $this->expectExceptionMessage('already been delivered in full');
        $this->orders->cancel($order->fresh());
    }

    /**
     * The buyer cancels while the storeroom has the delivery screen open.
     *
     * The draft/cancelled check was asked of the copy the caller brought, once,
     * before the transaction. The order is locked and re-read inside — but the
     * answer was never re-checked, so goods could be received onto the shelf
     * against an order that had just been cancelled.
     */
    public function test_an_order_cancelled_mid_delivery_is_refused(): void
    {
        $order = $this->orders->send($this->draft(20));

        // The delivery screen loaded this copy; the cancellation lands after.
        $stale = PurchaseOrder::with('items')->findOrFail($order->id);

        $this->orders->cancel($order);

        try {
            $this->orders->receive($stale, $this->buyer, [
                ['purchase_order_item_id' => $stale->items->first()->id, 'quantity' => 20],
            ]);
            $this->fail('A cancelled purchase order was received against.');
        } catch (StorefrontException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        $this->assertSame(
            0,
            (int) $this->product->fresh()->stock_quantity,
            'Stock landed against a cancelled order.',
        );
    }

    // --- through the endpoints ---------------------------------------------

    public function test_the_whole_journey_through_the_api(): void
    {
        $created = $this->actingAs($this->buyer)->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'expected_on' => now()->addWeek()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 190000]],
        ])->assertOk()->json('data');

        $id = $created['id'];

        $this->actingAs($this->buyer)->postJson("/api/admin/purchase-orders/{$id}/send")->assertOk();

        $itemId = PurchaseOrder::find($id)->items()->first()->id;

        $this->actingAs($this->buyer)->postJson("/api/admin/purchase-orders/{$id}/receive", [
            'invoice_number' => 'INV-9911',
            'lines' => [['purchase_order_item_id' => $itemId, 'quantity' => 4]],
        ])->assertOk()->assertJsonPath('message', 'Received. 6 still to come on '.$created['reference'].' — receive the rest when it arrives.');

        $this->assertSame(4, $this->product->fresh()->stock_quantity);
        $this->assertSame(PurchaseOrder::PARTIAL, PurchaseOrder::find($id)->status);
    }

    /** The details: each delivery, where its units went, and its serials. */
    public function test_the_details_show_every_delivery_and_its_branches(): void
    {
        $a = Store::create(['name' => 'Alpha Branch', 'slug' => 'alpha', 'city' => 'Dhaka', 'address' => '1', 'phone' => '01700000001', 'is_active' => true, 'holds_stock' => true, 'sort_order' => 1]);
        $b = Store::create(['name' => 'Beta Branch', 'slug' => 'beta', 'city' => 'Dhaka', 'address' => '2', 'phone' => '01700000002', 'is_active' => true, 'holds_stock' => true, 'sort_order' => 2]);
        $order = $this->draft(10);
        $itemId = $order->items()->first()->id;

        $this->actingAs($this->buyer)->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
            'store_id' => $a->id,
            'invoice_number' => 'INV-1',
            'note' => 'First boxes',
            'lines' => [['purchase_order_item_id' => $itemId, 'quantity' => 6, 'branches' => [$a->id => 4, $b->id => 2]]],
        ])->assertOk();

        $data = $this->actingAs($this->buyer)->getJson("/api/admin/purchase-orders/{$order->id}")
            ->assertOk()->json('data');

        $this->assertSame($order->reference, $data['order']['reference']);
        $this->assertSame(4, $data['order']['outstanding']);
        $this->assertCount(1, $data['deliveries']);
        $delivery = $data['deliveries'][0];
        $this->assertSame('INV-1', $delivery['invoice_number']);
        $this->assertSame('First boxes', $delivery['note']);
        $this->assertSame('Nayem', $delivery['received_by']);
        $this->assertSame(6, $delivery['lines'][0]['quantity']);
        $this->assertEqualsCanonicalizing(
            [['name' => 'Alpha Branch', 'quantity' => 4], ['name' => 'Beta Branch', 'quantity' => 2]],
            $delivery['lines'][0]['branches']
        );
    }

    public function test_purchasing_needs_the_stock_ability(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->postJson('/api/admin/purchase-orders', [
                'supplier_id' => $this->supplier->id,
                'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);
    }

    public function test_the_page_lists_them(): void
    {
        $this->orders->send($this->draft());

        $this->actingAs($this->buyer)->get('/admin/purchase-orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Purchasing')
                ->has('orders.data', 1)
                ->where('orders.data.0.status', PurchaseOrder::SENT));
    }

    // --- what things cost --------------------------------------------------

    /**
     * Stock cannot be received without a price.
     *
     * A movement with no unit_cost is skipped by every costed query — the
     * valuation, the latest-cost lookup, the margin on anything sold from
     * those units. The stock lands on the shelf and counts for nothing, and
     * nobody goes looking for a number that was never wrong, only absent.
     */
    public function test_an_uncosted_delivery_is_refused(): void
    {
        $order = $this->draft(quantity: 5, cost: null);
        $this->orders->send($order);

        $itemId = $order->items()->first()->id;

        $this->actingAs($this->buyer)
            ->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
                'lines' => [['purchase_order_item_id' => $itemId, 'quantity' => 5]],
            ])
            ->assertStatus(422);

        // And nothing moved: the shelf is where it was.
        $this->assertSame(0, $this->product->fresh()->stock_quantity);
    }

    /** Giving the price at the door is enough; the draft need not have had one. */
    public function test_an_uncosted_order_can_be_received_by_pricing_it_now(): void
    {
        $order = $this->draft(quantity: 5, cost: null);
        $this->orders->send($order);

        $itemId = $order->items()->first()->id;

        $this->actingAs($this->buyer)
            ->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
                'lines' => [[
                    'purchase_order_item_id' => $itemId,
                    'quantity' => 5,
                    'unit_cost' => 1750,
                ]],
            ])
            ->assertOk();

        $this->assertSame(5, $this->product->fresh()->stock_quantity);
        $this->assertSame(
            1750.0,
            (float) StockMovement::whereNotNull('unit_cost')->latest('id')->value('unit_cost')
        );
    }

    /** The invoice is what was paid, and it outranks what was quoted. */
    public function test_the_invoice_price_beats_the_quoted_one(): void
    {
        $order = $this->draft(quantity: 4, cost: 200000);
        $this->orders->send($order);

        $itemId = $order->items()->first()->id;

        $this->actingAs($this->buyer)
            ->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
                'lines' => [[
                    'purchase_order_item_id' => $itemId,
                    'quantity' => 4,
                    'unit_cost' => 185000,
                ]],
            ])
            ->assertOk();

        $this->assertSame(
            185000.0,
            (float) StockMovement::whereNotNull('unit_cost')->latest('id')->value('unit_cost')
        );
    }

    /**
     * A draft's prices can be corrected.
     *
     * The screen offered Send, Receive and Cancel and nothing else, so an
     * order saved with the wrong price — or with none — could not be put
     * right. The endpoint existed the whole time; nothing called it.
     */
    public function test_a_draft_can_be_repriced(): void
    {
        $order = $this->draft(quantity: 6, cost: null);

        $this->actingAs($this->buyer)
            ->putJson("/api/admin/purchase-orders/{$order->id}", [
                'supplier_id' => $this->supplier->id,
                'lines' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 6,
                    'unit_cost' => 199000,
                ]],
            ])
            ->assertOk();

        $this->assertSame(199000.0, (float) $order->fresh()->items()->first()->unit_cost);
    }

    /** Once everything has arrived, the prices are what was paid. */
    public function test_a_delivered_order_cannot_be_repriced(): void
    {
        $order = $this->draft(quantity: 6, cost: 200000);
        $this->orders->receive($order, $this->buyer, [
            ['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 6],
        ]);

        $this->actingAs($this->buyer)
            ->putJson("/api/admin/purchase-orders/{$order->id}", [
                'supplier_id' => $this->supplier->id,
                'lines' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 6,
                    'unit_cost' => 1,
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(200000.0, (float) $order->fresh()->items()->first()->unit_cost);
    }
}
