<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ShopDate;
use Illuminate\Support\Facades\DB;

/**
 * Asking a supplier for stock, and checking what turns up against what was asked.
 *
 * Receiving already worked; ordering did not exist. Between placing an order
 * and its arrival the shop held no record of it, so nobody could answer "when
 * are those back in", nobody could tell a supplier who shipped fifteen of
 * twenty that they still owed five, and an invoice had nothing to be checked
 * against.
 *
 * Receiving still goes through StockService, which stays the only thing that
 * writes a stock balance. This decides what a delivery means for the order it
 * was against; it does not move a unit itself.
 */
class PurchaseOrderService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Write or rewrite an order.
     *
     * @param  array<int, array{product_id:int, product_variant_id:?int, quantity:int, unit_cost:?float}>  $lines
     */
    public function save(
        ?PurchaseOrder $order,
        Supplier $supplier,
        User $user,
        array $lines,
        array $header = [],
    ): PurchaseOrder {
        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));

        if ($lines === []) {
            throw new StorefrontException(
                'Add at least one product with a quantity before saving the order.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        if ($order && ! $order->isEditable()) {
            throw new StorefrontException(
                $order->status === PurchaseOrder::CANCELLED
                    ? 'This order was cancelled, so it can no longer be changed.'
                    : 'Everything on this order has arrived, so it can no longer be changed.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        // The same product twice is one line.
        $merged = [];
        foreach ($lines as $line) {
            $key = (int) $line['product_id'].':'.(int) ($line['product_variant_id'] ?? 0);
            if (isset($merged[$key])) {
                $merged[$key]['quantity'] = (int) $merged[$key]['quantity'] + (int) $line['quantity'];
            } else {
                $merged[$key] = $line;
            }
        }
        $lines = $merged;

        return DB::transaction(function () use ($order, $supplier, $user, $lines, $header) {
            if ($order) {
                $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $received = $order->items()->with(['product:id,name', 'variant:id,name'])->get()->keyBy(
                    fn ($i) => $i->product_id.':'.(int) $i->product_variant_id
                );

                // What arrived is a record, not a plan: it cannot be edited away.
                if ($received->sum('quantity_received') > 0 && (int) $order->supplier_id !== (int) $supplier->id) {
                    throw new StorefrontException(
                        'Part of this order has already arrived from '.$order->supplier_name
                            .', so the supplier cannot be changed. Cancel what is still owed and '
                            .'write a new order for the other supplier.',
                        422,
                        ApiCode::VALIDATION_ERROR
                    );
                }

                foreach ($received as $key => $item) {
                    if ($item->quantity_received === 0) {
                        continue;
                    }

                    $asked = (int) ($lines[$key]['quantity'] ?? 0);

                    if ($asked < $item->quantity_received) {
                        throw new StorefrontException(
                            "{$item->display_name}: {$item->quantity_received} have already arrived, "
                                .($asked === 0
                                    ? 'so it cannot be taken off the order.'
                                    : "so the quantity cannot go below {$item->quantity_received}."),
                            422,
                            ApiCode::VALIDATION_ERROR
                        );
                    }
                }
            }

            $order ??= new PurchaseOrder([
                'reference' => PurchaseOrder::nextReference(),
                'status' => PurchaseOrder::SENT,
                'sent_at' => now(),
                'user_id' => $user->id,
                'ordered_by_name' => $user->name,
            ]);

            $order->fill([
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'store_id' => $header['store_id'] ?? null,
                'expected_on' => $header['expected_on'] ?? null,
                'note' => $header['note'] ?? null,
            ])->save();

            /*
             * Changed in place, not rewritten: a line that has had deliveries
             * against it carries how many arrived, and the deliveries point at
             * it. Lines dropped from the form go only if nothing arrived.
             */
            $existing = $order->items()->get()->keyBy(
                fn ($i) => $i->product_id.':'.(int) $i->product_variant_id
            );

            foreach ($existing as $key => $item) {
                if (! isset($lines[$key])) {
                    $item->delete();
                }
            }

            $quantity = 0;
            $cost = 0.0;

            foreach ($lines as $line) {
                [$product, $variant] = $this->stock->resolveUnit(
                    (int) $line['product_id'],
                    isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : null
                );

                $unitCost = isset($line['unit_cost']) && $line['unit_cost'] !== ''
                    ? (float) $line['unit_cost']
                    : null;

                $item = $existing->get($product->id.':'.(int) $variant?->id);

                if ($item) {
                    $item->update(['quantity' => (int) $line['quantity'], 'unit_cost' => $unitCost]);
                } else {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'quantity' => (int) $line['quantity'],
                        'unit_cost' => $unitCost,
                    ]);
                }

                $quantity += (int) $line['quantity'];
                $cost += $unitCost !== null ? $unitCost * (int) $line['quantity'] : 0.0;
            }

            $order->update([
                'total_quantity' => $quantity,
                'total_cost' => round($cost, 2),
            ]);

            // Lowering a line to what has arrived closes the order.
            $this->syncStatus($order->fresh('items'));

            return $order->fresh(['items.product', 'items.variant', 'supplier', 'store']);
        });
    }

    /**
     * Kept for an old draft, or a screen still open from before orders were
     * open on saving. An order already open is simply returned.
     */
    public function send(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status === PurchaseOrder::DRAFT) {
            $order->update(['status' => PurchaseOrder::SENT, 'sent_at' => now()]);
        }

        return $order->fresh();
    }

    /**
     * Take in a delivery against this order.
     *
     * The quantities are what actually arrived, which is the whole point:
     * fifteen against an order for twenty leaves five outstanding and the order
     * part-delivered, rather than silently closing it.
     *
     * @param  array<int, array{purchase_order_item_id:int, quantity:int, unit_cost?:float}>  $lines
     */
    public function receive(PurchaseOrder $order, User $user, array $lines, array $header = []): StockReceipt
    {
        return DB::transaction(function () use ($order, $user, $lines, $header) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            /*
             * Asked of the locked row, not of the copy the caller brought. The
             * buyer cancelling while the storeroom has the delivery screen open
             * used to be invisible here, and the goods landed on the shelf
             * against an order that no longer existed to receive them.
             */
            if ($order->status === PurchaseOrder::CANCELLED) {
                throw new StorefrontException('This order was cancelled.', 422, ApiCode::VALIDATION_ERROR);
            }
            $items = $order->items()->get()->keyBy('id');
            $receiptLines = [];

            foreach ($lines as $line) {
                $item = $items->get((int) ($line['purchase_order_item_id'] ?? 0));
                $quantity = (int) ($line['quantity'] ?? 0);

                if (! $item || $quantity <= 0) {
                    continue;
                }

                /*
                 * More than was ordered is refused. A supplier sending extra
                 * happens, and it is a conversation rather than something to
                 * absorb quietly — booking it in against this order would
                 * leave the order reading as over-delivered and the invoice
                 * disagreeing with the paperwork.
                 */
                if ($item->quantity_received + $quantity > $item->quantity) {
                    $room = max(0, $item->quantity - $item->quantity_received);

                    throw new StorefrontException(
                        "{$item->display_name}: only {$room} still to come on this order, "
                            ."and {$quantity} were entered. Receive {$room} here, then book the extra "
                            .'with Stock → Receive delivery, or edit the order first if the supplier '
                            .'agreed to send more.',
                        422,
                        ApiCode::VALIDATION_ERROR
                    );
                }

                // What was quoted, unless the invoice says otherwise.
                $unitCost = $line['unit_cost'] ?? $item->unit_cost;

                /*
                 * Refused rather than received without one.
                 *
                 * A stock movement with no cost is skipped by every costed
                 * query — valuation, the latest-cost lookup, margin on
                 * anything sold from these units. The stock would land on the
                 * shelf and quietly count for nothing, which is worse than
                 * being stopped here: nobody goes looking for a number that
                 * was never wrong, only absent.
                 *
                 * A draft may legitimately carry no price, because the
                 * supplier had not confirmed one when the order was raised.
                 * Receiving is where that stops being acceptable, and the
                 * invoice in hand is exactly when it is answerable.
                 */
                if ($unitCost === null || $unitCost === '') {
                    throw new StorefrontException(
                        "{$item->display_name} has no unit cost. Enter what it cost on the invoice — "
                            .'stock received without a price cannot be valued, and would not show in '
                            .'your margins.',
                        422,
                        ApiCode::VALIDATION_ERROR
                    );
                }

                // Before anything lands; the transaction undoes nothing yet.
                app(SerialService::class)->checkDelivery($item->display_name, $line['serials'] ?? null, $quantity);

                $receiptLines[] = [
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $quantity,
                    'unit_cost' => (float) $unitCost,
                    // Where it goes, when split between branches.
                    'branches' => $line['branches'] ?? null,
                    'serials' => $line['serials'] ?? null,
                ];

                $item->increment('quantity_received', $quantity);
            }

            if ($receiptLines === []) {
                throw new StorefrontException(
                    'Enter how many of each line actually arrived.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            // StockService remains the only thing that writes a balance.
            $receipt = $this->stock->receive(
                [
                    'supplier_id' => $order->supplier_id,
                    'supplier_name' => $order->supplier_name,
                    'store_id' => $header['store_id'] ?? $order->store_id,
                    'invoice_number' => $header['invoice_number'] ?? null,
                    'received_on' => $header['received_on'] ?? ShopDate::today(),
                    'note' => $header['note'] ?? "Against {$order->reference}.",
                ],
                $receiptLines,
                $user->id
            );

            $receipt->update(['purchase_order_id' => $order->id]);

            /*
             * Serials, when typed: the same as a delivery booked without an
             * order. This screen had no box for them, so a laptop bought on
             * an order arrived with no serial on the books.
             */
            $deliveryStore = $header['store_id'] ?? $order->store_id;

            foreach ($receiptLines as $line) {
                if (blank($line['serials'] ?? null)) {
                    continue;
                }

                [$product, $variant] = $this->stock->resolveUnit($line['product_id'], $line['product_variant_id']);

                app(SerialService::class)->receiveSplit(
                    $product,
                    $variant?->id,
                    preg_split('/[\r\n,]+/', (string) $line['serials']) ?: [],
                    $this->stock->receivingSplit($line, $line['quantity'], $deliveryStore, $product, $variant),
                    $receipt,
                );
            }

            $this->syncStatus($order->fresh('items'));

            return $receipt->fresh(['items.product', 'items.variant', 'supplier']);
        });
    }

    /** Nothing more is coming. */
    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status === PurchaseOrder::RECEIVED) {
            throw new StorefrontException(
                'This order has already been delivered in full.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $order->update(['status' => PurchaseOrder::CANCELLED]);

        return $order->fresh();
    }

    /**
     * Keep the status in step with what has actually turned up.
     *
     * Left alone once cancelled: that is a statement about the order rather
     * than about how much of it arrived, and a late delivery against a
     * cancelled order should not quietly reopen it.
     */
    public function syncStatus(PurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrder::CANCELLED) {
            return;
        }

        $outstanding = $order->items->sum(fn ($item) => max(0, $item->quantity - $item->quantity_received));
        $arrived = $order->items->sum('quantity_received');

        $order->update([
            'status' => match (true) {
                $outstanding === 0 => PurchaseOrder::RECEIVED,
                $arrived > 0 => PurchaseOrder::PARTIAL,
                default => PurchaseOrder::SENT,
            },
        ]);
    }

    /**
     * How many units of each thing are on their way.
     *
     * The question a buyer asks before ordering more, and the one a salesperson
     * asks when a customer wants something the shelf has run out of.
     *
     * @return array<string, int> keyed product:variant
     */
    public function onOrder(): array
    {
        return PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', fn ($q) => $q->open())
            ->get()
            ->groupBy(fn ($item) => $item->product_id.':'.($item->product_variant_id ?: ''))
            ->map(fn ($items) => (int) $items->sum(fn ($i) => max(0, $i->quantity - $i->quantity_received)))
            ->filter()
            ->all();
    }
}
