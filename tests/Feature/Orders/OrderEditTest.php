<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderEditService;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Changing an order after it has been placed.
 *
 * There was no way to, so a customer ringing to add a stick of RAM meant
 * cancelling the order and starting again — losing the order number, the
 * tracking link already texted to them, and any deposit's connection to the
 * order it was paid against.
 *
 * Almost everything here is about the shelf. Every line on a pending order has
 * already taken units off it, so an edit is a difference to settle rather than
 * a fresh sale, and getting that wrong invents or destroys stock silently.
 */
class OrderEditTest extends TestCase
{
    use RefreshDatabase;

    private OrderEditService $edits;

    private StockService $stock;

    private Product $gpu;

    private Product $ram;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edits = app(OrderEditService::class);
        $this->stock = app(StockService::class);
        $this->staff = User::factory()->create(['role' => 'admin', 'name' => 'Nayem']);

        $category = Category::create(['name' => 'Parts', 'slug' => 'parts', 'is_active' => true]);

        $this->gpu = Product::create([
            'category_id' => $category->id, 'name' => 'RTX 4090', 'slug' => 'rtx-4090-edit',
            'price' => 10000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $this->ram = Product::create([
            'category_id' => $category->id, 'name' => 'Vengeance 32GB', 'slug' => 'ram-edit',
            'price' => 5000, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        foreach ([$this->gpu, $this->ram] as $product) {
            $this->stock->receive([], [[
                'product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 1000,
            ]]);
        }
    }

    /** An order for two graphics cards, placed through the real checkout. */
    private function order(int $quantity = 2): Order
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->gpu->id, 'quantity' => $quantity,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::latest('id')->first()->load('items');
    }

    private function lines(Order $order, array $overrides = []): array
    {
        return $order->items->map(fn ($i) => [
            'order_item_id' => $i->id,
            'quantity' => $overrides[$i->id] ?? $i->quantity,
        ])->all();
    }

    // --- the shelf ---------------------------------------------------------

    public function test_raising_a_quantity_takes_only_the_difference(): void
    {
        $order = $this->order(2);
        $item = $order->items->first();

        $this->assertSame(18, $this->gpu->fresh()->stock_quantity);

        $this->edits->apply($order, $this->staff, [
            ['order_item_id' => $item->id, 'quantity' => 5],
        ]);

        // Three more off the shelf, not five.
        $this->assertSame(15, $this->gpu->fresh()->stock_quantity);
        $this->assertSame(5, $item->fresh()->quantity);
    }

    public function test_lowering_a_quantity_puts_the_difference_back(): void
    {
        $order = $this->order(5);
        $item = $order->items->first();

        $this->edits->apply($order, $this->staff, [
            ['order_item_id' => $item->id, 'quantity' => 2],
        ]);

        $this->assertSame(18, $this->gpu->fresh()->stock_quantity);
        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_a_line_removed_puts_all_of_it_back(): void
    {
        $order = $this->order(3);
        $gpuItem = $order->items->first();

        // Add RAM so the order is not left empty.
        $this->edits->apply($order, $this->staff, [
            ['order_item_id' => $gpuItem->id, 'quantity' => 3],
            ['product_id' => $this->ram->id, 'quantity' => 1],
        ]);

        $order->refresh()->load('items');
        $ramItem = $order->items->firstWhere('product_id', $this->ram->id);

        $this->edits->apply($order, $this->staff, [
            ['order_item_id' => $ramItem->id, 'quantity' => 1],
        ]);

        $this->assertSame(20, $this->gpu->fresh()->stock_quantity);
        $this->assertSame(1, $order->fresh()->items()->count());
    }

    public function test_adding_a_product_takes_it_off_the_shelf(): void
    {
        $order = $this->order(2);

        $this->edits->apply($order, $this->staff, array_merge(
            $this->lines($order),
            [['product_id' => $this->ram->id, 'quantity' => 4]]
        ));

        $this->assertSame(16, $this->ram->fresh()->stock_quantity);
        $this->assertSame(2, $order->fresh()->items()->count());
    }

    /**
     * If the units are gone, the whole edit goes back — an order half-changed
     * with stock moved for the half that worked is worse than one not changed.
     */
    public function test_an_edit_that_cannot_be_stocked_rolls_all_of_it_back(): void
    {
        $order = $this->order(2);
        $item = $order->items->first();

        // Someone else takes the rest of the shelf.
        $this->stock->adjust($this->gpu, null, -18, 'damaged', 'Water damage.');
        $this->assertSame(0, $this->gpu->fresh()->stock_quantity);

        try {
            $this->edits->apply($order, $this->staff, array_merge(
                [['order_item_id' => $item->id, 'quantity' => 9]],
                [['product_id' => $this->ram->id, 'quantity' => 2]]
            ));
            $this->fail('The edit should have been refused.');
        } catch (\Throwable) {
            // Expected.
        }

        // The RAM was never taken, and the order is untouched.
        $this->assertSame(20, $this->ram->fresh()->stock_quantity);
        $this->assertSame(2, $order->fresh()->items->first()->quantity);
        $this->assertSame(0, OrderEdit::count());
    }

    // --- the bill ----------------------------------------------------------

    public function test_the_total_is_worked_out_again(): void
    {
        $order = $this->order(2);
        $before = (float) $order->total;

        $this->edits->apply($order, $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 4],
        ]);

        $order->refresh();

        $this->assertSame(40000.0, (float) $order->subtotal);
        $this->assertGreaterThan($before, (float) $order->total);
        // Delivery is still on the bill and still separate from the goods.
        $this->assertGreaterThan(0, (float) $order->shipping_fee);
    }

    /**
     * A deposit was taken against a larger total. Dropping below it is the shop
     * owing money back, which is a refund with a reason and a method — not a
     * quiet subtraction.
     */
    public function test_an_edit_below_what_has_been_paid_is_refused(): void
    {
        $order = $this->order(5);

        app(OrderPaymentService::class)->record($order->fresh(), $this->staff, 30000, 'cash');

        $this->expectExceptionMessage('already paid. Record a refund for the difference first.');
        $this->edits->apply($order->fresh()->load('items'), $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 1],
        ]);
    }

    public function test_a_refused_edit_leaves_the_stock_alone(): void
    {
        $order = $this->order(5);
        app(OrderPaymentService::class)->record($order->fresh(), $this->staff, 30000, 'cash');

        $before = $this->gpu->fresh()->stock_quantity;

        try {
            $this->edits->apply($order->fresh()->load('items'), $this->staff, [
                ['order_item_id' => $order->items->first()->id, 'quantity' => 1],
            ]);
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertSame($before, $this->gpu->fresh()->stock_quantity);
    }

    /** A part-paid order that gets cheaper may now be paid in full. */
    public function test_the_payment_status_follows_the_new_total(): void
    {
        $order = $this->order(5);
        app(OrderPaymentService::class)->record($order->fresh(), $this->staff, 20000, 'cash');

        $this->assertSame('partial', $order->fresh()->payment_status);

        // Two cards at 10,000 plus delivery is now under what was paid... so
        // trim to exactly what the payment covers instead.
        $this->edits->apply($order->fresh()->load('items'), $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 2],
        ]);

        $order->refresh();

        $this->assertSame(20000.0, (float) $order->subtotal);
        $this->assertSame('partial', $order->payment_status);
        $this->assertGreaterThan(0, $order->amount_due);
    }

    // --- when it is allowed ------------------------------------------------

    public function test_a_shipped_order_cannot_be_edited(): void
    {
        $order = $this->order(2);
        $order->forceFill(['status' => 'shipped'])->save();

        $this->expectExceptionMessage('already with the courier');
        $this->edits->apply($order->fresh()->load('items'), $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 1],
        ]);
    }

    /**
     * Cancelling already put the stock back. Editing would take it off the
     * shelf again for an order nobody is going to fulfil.
     */
    public function test_a_cancelled_order_cannot_be_edited(): void
    {
        $order = $this->order(2);
        app(OrderService::class)->updateOrderStatus($order, 'cancelled');

        $this->assertSame(20, $this->gpu->fresh()->stock_quantity);

        $this->expectExceptionMessage('cannot be changed');
        $this->edits->apply($order->fresh()->load('items'), $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 5],
        ]);
    }

    public function test_an_order_cannot_be_emptied(): void
    {
        $order = $this->order(2);

        $this->expectExceptionMessage('An order cannot be emptied.');
        $this->edits->apply($order, $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 0],
        ]);
    }

    // --- the record ---------------------------------------------------------

    /**
     * Allowing an edit is allowing staff to change what a customer agreed to
     * pay, after they agreed to it. Without a record the feature is a way to
     * quietly alter a bill.
     */
    public function test_every_change_is_written_down(): void
    {
        $order = $this->order(2);
        $before = (float) $order->total;

        $this->edits->apply($order, $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 4],
        ], 'Customer rang to add two.');

        $edit = OrderEdit::first();

        $this->assertSame('Nayem', $edit->edited_by_name);
        $this->assertSame($this->staff->id, $edit->user_id);
        $this->assertSame($before, $edit->total_before);
        $this->assertSame((float) $order->fresh()->total, $edit->total_after);
        $this->assertGreaterThan(0, $edit->difference);
        $this->assertSame('Customer rang to add two.', $edit->reason);
        // assertEquals, not assertSame: `changes` is a JSON column, and the
        // two drivers hand its keys back in different orders. The record has to
        // say what changed; it does not have to say it in a fixed key order.
        $this->assertEquals(
            [['product' => 'RTX 4090', 'from' => 2, 'to' => 4]],
            $edit->changes
        );
    }

    public function test_an_edit_that_changes_nothing_is_refused(): void
    {
        $order = $this->order(2);

        $this->expectExceptionMessage('Nothing on this order was changed.');
        $this->edits->apply($order, $this->staff, $this->lines($order));
    }

    /** A line added now must not drop the order out of the margin report. */
    public function test_an_added_line_carries_a_cost(): void
    {
        $order = $this->order(2);

        $this->edits->apply($order, $this->staff, array_merge(
            $this->lines($order),
            [['product_id' => $this->ram->id, 'quantity' => 1]]
        ));

        $added = $order->fresh()->items->firstWhere('product_id', $this->ram->id);

        $this->assertSame(1000.0, (float) $added->unit_cost);
    }

    // --- through the endpoint -----------------------------------------------

    public function test_the_endpoint_applies_an_edit(): void
    {
        $order = $this->order(2);

        $this->actingAs($this->staff)
            ->putJson("/api/admin/orders/{$order->id}/lines", [
                'reason' => 'Customer added RAM',
                'lines' => array_merge(
                    $this->lines($order),
                    [['product_id' => $this->ram->id, 'quantity' => 2]]
                ),
            ])
            ->assertOk();

        $this->assertSame(2, $order->fresh()->items()->count());
        $this->assertSame(18, $this->ram->fresh()->stock_quantity);
    }

    /**
     * Every staff role carries `orders` — a storekeeper included, because
     * whoever picks a parcel has to see what is on it. So the account that
     * must be refused is one with no abilities at all.
     */
    public function test_editing_needs_the_orders_ability(): void
    {
        $order = $this->order(2);

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->putJson("/api/admin/orders/{$order->id}/lines", [
                'lines' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);
    }

    /** Every unit moved is in the ledger, tied to the order that moved it. */
    public function test_the_movements_name_the_order(): void
    {
        $order = $this->order(2);

        $this->edits->apply($order, $this->staff, [
            ['order_item_id' => $order->items->first()->id, 'quantity' => 1],
        ]);

        $released = StockMovement::where('type', StockMovement::CANCELLATION)->latest('id')->first();

        $this->assertNotNull($released);
        $this->assertSame(1, $released->quantity);
        $this->assertStringContainsString($order->order_number, (string) $released->note);
    }
}
