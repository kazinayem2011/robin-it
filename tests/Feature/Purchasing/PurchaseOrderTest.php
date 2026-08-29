<?php

namespace Tests\Feature\Purchasing;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
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

    // --- writing one -------------------------------------------------------

    public function test_an_order_records_what_was_asked_for(): void
    {
        $order = $this->draft();

        $this->assertSame(PurchaseOrder::DRAFT, $order->status);
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

    /**
     * A draft is a piece of paper being edited. Once it is with the supplier,
     * changing the lines would leave the shop's copy disagreeing with the one
     * they are picking from.
     */
    public function test_an_order_already_sent_cannot_be_rewritten(): void
    {
        $order = $this->orders->send($this->draft());

        $this->expectExceptionMessage('already with the supplier');
        $this->orders->save($order, $this->supplier, $this->buyer, [
            ['product_id' => $this->product->id, 'quantity' => 5],
        ]);
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

        $this->expectExceptionMessage('only 20 still outstanding on this order, and 25 were entered');
        $this->orders->receive($order, $this->buyer, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 25],
        ]);
    }

    public function test_a_draft_cannot_be_received_against(): void
    {
        $order = $this->draft();

        $this->expectExceptionMessage('Send the order to the supplier before receiving against it.');
        $this->orders->receive($order, $this->buyer, [
            ['purchase_order_item_id' => $order->items()->first()->id, 'quantity' => 1],
        ]);
    }

    // --- what is on its way -------------------------------------------------

    /**
     * The question a buyer asks before ordering more, and a salesperson asks
     * when the shelf has run out.
     */
    public function test_what_is_on_order_counts_only_what_is_still_coming(): void
    {
        $key = $this->product->id.':';

        $this->draft(20);                            // still a draft
        $this->assertSame([], $this->orders->onOrder());

        $sent = $this->orders->send($this->draft(30));
        $this->assertSame(30, $this->orders->onOrder()[$key]);

        $this->orders->receive($sent, $this->buyer, [
            ['purchase_order_item_id' => $sent->items()->first()->id, 'quantity' => 12],
        ]);

        $this->assertSame(18, $this->orders->onOrder()[$key]);
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
        ])->assertOk()->assertJsonPath('message', 'Received. 6 still outstanding on '.$created['reference'].'.');

        $this->assertSame(4, $this->product->fresh()->stock_quantity);
        $this->assertSame(PurchaseOrder::PARTIAL, PurchaseOrder::find($id)->status);
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
}
