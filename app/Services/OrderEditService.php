<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\Order;
use App\Models\OrderEdit;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ShippingRates;
use App\Support\VatRules;
use Illuminate\Support\Facades\DB;

/**
 * Changing an order after it has been placed.
 *
 * There was no way to. A customer ringing to add a stick of RAM to an order
 * placed an hour ago meant cancelling it and starting again — losing the order
 * number, the tracking link already texted to them, and any deposit's
 * connection to the order it was paid against.
 *
 * Three things make this harder than editing a row.
 *
 * Stock is already reserved. Every line on a pending order has taken units off
 * the shelf, so a change is a difference to settle, not a new sale: two more of
 * something takes two more, one fewer puts one back, and a line removed puts
 * the lot back. StockService remains the only thing that writes a balance.
 *
 * Money may already have moved. A deposit was taken against a total, and an
 * edit that drops the total below what has been paid is the shop owing money
 * back — which is a refund, with a reason and a method, not a quiet subtraction.
 *
 * And somebody is changing what a customer agreed to pay, after they agreed to
 * it. Every edit is written down with who made it and what it did to the bill.
 */
class OrderEditService
{
    /**
     * When an order can still be changed.
     *
     * Not once it is shipped: the parcel is with a courier, the consignment is
     * booked against a value, and editing the lines would leave the paperwork
     * disagreeing with what is in the box. Not once cancelled or returned
     * either — those have already put the stock back, and editing would take it
     * off the shelf again for an order nobody is fulfilling.
     */
    public const EDITABLE = ['pending', 'processing'];

    public function __construct(private readonly StockService $stock) {}

    public function canEdit(Order $order): bool
    {
        return in_array($order->status, self::EDITABLE, true)
            && $order->stock_released_at === null;
    }

    /**
     * Apply a new set of lines to an order.
     *
     * The whole list, not a patch: quantities, additions and removals arrive
     * together and settle together, so an edit that fails part way cannot leave
     * an order half-changed with stock moved for the half that worked.
     *
     * @param  array<int, array{order_item_id?:int, product_id?:int, product_variant_id?:?int, quantity:int}>  $lines
     */
    public function apply(Order $order, User $staff, array $lines, ?string $reason = null): Order
    {
        if (! $this->canEdit($order)) {
            throw new StorefrontException(
                $order->status === 'shipped' || $order->status === 'delivered'
                    ? 'This order is already with the courier. Take it back as a return instead.'
                    : "An order that is {$order->status} cannot be changed.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return DB::transaction(function () use ($order, $staff, $lines, $reason) {
            // Locked and re-read: two people editing the same order would
            // otherwise each settle their own difference against a stale
            // picture and move stock twice.
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->load('items');

            $totalBefore = (float) $order->total;
            $existing = $order->items->keyBy('id');
            $changes = [];
            $keptIds = [];

            foreach ($lines as $line) {
                $quantity = max(0, (int) ($line['quantity'] ?? 0));
                $itemId = $line['order_item_id'] ?? null;

                if ($itemId && $existing->has($itemId)) {
                    $item = $existing->get($itemId);

                    if ($quantity === 0) {
                        // Removed: everything it held goes back.
                        $this->move($order, $item, -$item->quantity);
                        $changes[] = $this->note($item->product_name, $item->quantity, 0);
                        $item->delete();

                        continue;
                    }

                    $delta = $quantity - $item->quantity;

                    if ($delta !== 0) {
                        $this->move($order, $item, $delta);
                        $changes[] = $this->note($item->product_name, $item->quantity, $quantity);

                        $item->update([
                            'quantity' => $quantity,
                            'total' => round((float) $item->price * $quantity, 2),
                        ]);
                    }

                    $keptIds[] = $item->id;

                    continue;
                }

                if ($quantity > 0 && ! empty($line['product_id'])) {
                    $added = $this->addLine($order, $line, $quantity);
                    $changes[] = $this->note($added->product_name, 0, $quantity);
                    $keptIds[] = $added->id;
                }
            }

            /*
             * A line the caller did not mention is a line they removed. The
             * screen sends every row it is showing, so silence means gone —
             * and treating it as "leave it alone" would make removing the last
             * line impossible.
             */
            foreach ($existing as $item) {
                if (in_array($item->id, $keptIds, true) || ! $item->exists) {
                    continue;
                }

                $this->move($order, $item, -$item->quantity);
                $changes[] = $this->note($item->product_name, $item->quantity, 0);
                $item->delete();
            }

            if ($changes === []) {
                throw new StorefrontException(
                    'Nothing on this order was changed.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            $order->load('items');

            if ($order->items->isEmpty()) {
                throw new StorefrontException(
                    'An order cannot be emptied. Cancel it instead, which puts the stock back '
                        .'and tells the customer.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            $this->retotal($order);

            /*
             * Checked after the new total is known, and inside the transaction
             * so it rolls the stock back with it. Money already taken against
             * a larger total is the shop owing a refund, which carries a reason
             * and a method and belongs in the refunds ledger.
             */
            if ($order->amount_paid > $order->total) {
                throw new StorefrontException(
                    'That would take the order below the '.number_format($order->amount_paid, 2)
                        .' already paid. Record a refund for the difference first.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            OrderEdit::create([
                'order_id' => $order->id,
                'user_id' => $staff->id,
                'edited_by_name' => $staff->name,
                'total_before' => $totalBefore,
                'total_after' => $order->total,
                'changes' => $changes,
                'reason' => $reason,
            ]);

            // The old flag must not contradict the new total — a part-paid
            // order that is now cheaper may have become paid in full.
            app(OrderPaymentService::class)->syncStatus($order);

            return $order->fresh(['items.product', 'items.variant', 'edits']);
        });
    }

    /**
     * Settle the difference on the shelf.
     *
     * Positive takes more units, which can fail because somebody else may have
     * bought them in the meantime — the whole edit then rolls back, which is
     * the right answer: an order half-changed is worse than one not changed.
     */
    private function move(Order $order, OrderItem $item, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        [$product, $variant] = $this->unitFor($item);

        if (! $product) {
            return;
        }

        if ($delta > 0) {
            $this->stock->sell($product, $variant, $delta, $order);

            return;
        }

        $this->stock->releaseToShelf(
            $product,
            $variant,
            abs($delta),
            $order,
            "Order {$order->order_number} edited."
        );
    }

    private function addLine(Order $order, array $line, int $quantity): OrderItem
    {
        [$product, $variant] = app(StockService::class)->resolveUnit(
            (int) $line['product_id'],
            isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : null
        );

        if (! $product->is_active) {
            throw new StorefrontException(
                "{$product->name} is no longer for sale.",
                422,
                ApiCode::PRODUCT_UNAVAILABLE
            );
        }

        // Today's price, not the price when the order was placed. A line added
        // now is bought now, and pretending otherwise gives away margin nobody
        // decided to give away.
        $price = $variant
            ? (float) ($variant->discount_price ?: $variant->price)
            : (float) ($product->discount_price ?: $product->price);

        $this->stock->sell($product, $variant, $quantity, $order);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'product_name' => $product->name,
            'variant_name' => $variant?->name,
            'price' => $price,
            'quantity' => $quantity,
            'total' => round($price * $quantity, 2),
            // What it cost the shop, so the margin report can still read this
            // order. Without it the whole order drops out of the figures.
            'unit_cost' => $this->stock->latestUnitCosts()[$product->id.':'.($variant?->id ?: '')] ?? null,
        ]);
    }

    /**
     * Work the bill out again from the lines that are now on it.
     *
     * The same rules checkout uses, applied to the order rather than to a cart:
     * VAT on the goods after the discount and not on delivery, and the delivery
     * fee measured against the goods before the coupon so a promo code cannot
     * cost the customer their free delivery.
     */
    private function retotal(Order $order): void
    {
        $subtotal = round($order->items->sum(fn ($i) => (float) $i->price * $i->quantity), 2);

        /*
         * A percentage coupon follows the new subtotal; a fixed one does not,
         * because "200 off" is 200 whatever the basket is. Either is capped at
         * the subtotal, so an edit that shrinks an order below its discount
         * cannot make the goods free.
         */
        $discount = match ($order->coupon_discount_type) {
            'percentage' => round($subtotal * ((float) $order->coupon_discount_value) / 100, 2),
            'fixed' => (float) $order->coupon_discount_value,
            default => (float) $order->discount,
        };

        $discount = round(min(max($discount, 0.0), $subtotal), 2);

        $goods = round($subtotal - $discount, 2);
        $vat = VatRules::on($goods);
        $addedOnTop = $vat > 0 && ! VatRules::pricesIncludeVat();
        $shipping = ShippingRates::feeFor($order->shipping_address['city'] ?? null, $subtotal);

        $order->forceFill([
            'subtotal' => $subtotal,
            'discount' => $discount,
            'vat_amount' => $vat,
            'vat_rate' => $vat > 0 ? VatRules::rate() : null,
            'vat_inclusive' => VatRules::pricesIncludeVat(),
            'shipping_fee' => $shipping,
            'total' => round(max(0.0, $goods + ($addedOnTop ? $vat : 0.0) + $shipping), 2),
        ])->save();
    }

    /** @return array{0: ?Product, 1: ?ProductVariant} */
    private function unitFor(OrderItem $item): array
    {
        if (! $item->product_id) {
            return [null, null];
        }

        try {
            [$product, $variant] = $this->stock->resolveUnit(
                $item->product_id,
                $item->product_variant_id
            );

            return [$product, $variant];
        } catch (\Throwable) {
            // The product was deleted since. Nothing to put back or take.
            return [null, null];
        }
    }

    /**
     * @return array{product: string, from: int, to: int}
     */
    private function note(string $name, int $from, int $to): array
    {
        return ['product' => $name, 'from' => $from, 'to' => $to];
    }
}
