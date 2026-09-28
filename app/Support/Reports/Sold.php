<?php

namespace App\Support\Reports;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What was actually sold, the one rule every report counts by.
 *
 * A sale counts once the goods reach the customer, on the day they did: an
 * order still pending can be cancelled next week, and counting it made this
 * week look better than it was.
 *
 * It counts what the customer kept. Items that came back come off the sale
 * and its cost. Money given back on top of that — a goodwill refund with
 * nothing returned — comes off too. Refunds on cancelled or fully returned
 * orders are not counted at all: those orders are not sales, so taking their
 * refunds off as well took the same money away twice.
 */
class Sold
{
    /** Delivered orders whose delivery day falls in the period. */
    public static function orders(?string $from, ?string $to): Builder
    {
        return Order::query()
            ->where('status', 'delivered')
            ->when($from, fn ($q) => $q->whereDate('delivered_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('delivered_at', '<=', $to));
    }

    /** Their lines, for figures by product. */
    public static function lines(?string $from, ?string $to): QueryBuilder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'delivered')
            ->when($from, fn ($q) => $q->whereDate('orders.delivered_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('orders.delivered_at', '<=', $to));
    }

    /** The orders with what figures() reads, loaded once. */
    public static function load(?string $from, ?string $to): Collection
    {
        return self::orders($from, $to)->with(['items', 'refunds'])->get();
    }

    /**
     * One delivered order, as the reports count it.
     *
     * @return array{
     *     goods:float, vat:float, vat_returned:float, delivery:float,
     *     given_back:float, cost:float|null, units:int, spent:float
     * }
     */
    public static function figures(Order $order): array
    {
        $subtotal = (float) $order->subtotal;
        $kept = $order->items->sum(fn (OrderItem $i) => $i->returnable_quantity * (float) $i->price);

        // The discount and VAT are shared across the lines by value, the
        // same way the order's returned value is worked out.
        // An order with no lines left has nothing to share by, so it counts
        // whole — and as uncosted below, never as a free sale.
        $share = $subtotal > 0 && $order->items->isNotEmpty() ? min(1.0, $kept / $subtotal) : 1.0;
        $gross = ((float) $subtotal - (float) $order->discount) * $share;
        $vat = (float) $order->vat_amount * $share;
        $goods = $order->vat_inclusive ? $gross - $vat : $gross;

        $refunded = $order->refunds
            ->where('method', '!=', 'cod_not_collected')
            ->sum(fn ($r) => (float) $r->amount);
        $givenBack = max(0.0, $refunded - $order->returned_value);

        // Money given back carries its share of the VAT with it, the same as
        // goods that came back: half the price back is half its VAT back.
        $charged = (float) $order->total - (float) $order->shipping_fee;
        $givenBackVat = $charged > 0 ? min($vat, $givenBack * (float) $order->vat_amount / $charged) : 0.0;
        $vat -= $givenBackVat;

        $costed = $order->items->isNotEmpty() && $order->items->every(fn (OrderItem $i) => $i->unit_cost !== null);

        return [
            'goods' => round($goods, 2),
            'vat' => round($vat, 2),
            'vat_returned' => round((float) $order->vat_amount - $vat, 2),
            'delivery' => round((float) $order->shipping_fee, 2),
            // Net of VAT, like the goods it comes off.
            'given_back' => round($givenBack - $givenBackVat, 2),
            'cost' => $costed
                ? round($order->items->sum(fn (OrderItem $i) => $i->returnable_quantity * (float) $i->unit_cost), 2)
                : null,
            'units' => (int) $order->items->sum(fn (OrderItem $i) => $i->returnable_quantity),
            // What the customer ended up paying for, delivery included.
            'spent' => round(max(0.0, (float) $order->total - $order->returned_value - $givenBack), 2),
        ];
    }

    /**
     * Stock that left without being sold, at what it cost.
     *
     * Damaged returns, write-offs and stock-count corrections. A count that
     * found more than expected counts against the losses. Stock sent back to
     * a supplier is not a loss — it goes back for credit — so it is left out.
     *
     * Movements carry no cost of their own. A damaged return is priced at the
     * cost on its order line; anything else at the last price paid for that
     * item before it happened.
     *
     * @return array{amount:float, units:int, uncosted:int}
     */
    public static function stockLost(?string $from, ?string $to): array
    {
        $movements = StockMovement::query()
            ->where(fn ($q) => $q->where('type', StockMovement::WRITE_OFF)
                ->orWhere(fn ($q) => $q->where('type', StockMovement::ADJUSTMENT)
                    ->where(fn ($q) => $q->whereNull('reason')->orWhere('reason', '!=', 'supplier_return'))))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->get(['id', 'product_id', 'product_variant_id', 'quantity', 'type', 'reference_type', 'reference_id']);

        $orderType = (new Order)->getMorphClass();
        $amount = 0.0;
        $units = 0;
        $uncosted = 0;

        foreach ($movements as $m) {
            $cost = null;

            if ($m->reference_type === $orderType) {
                $cost = OrderItem::where('order_id', $m->reference_id)
                    ->where('product_id', $m->product_id)
                    ->where('product_variant_id', $m->product_variant_id)
                    ->value('unit_cost');
            }

            $cost ??= StockMovement::query()
                ->where('product_id', $m->product_id)
                ->where('product_variant_id', $m->product_variant_id)
                ->where('id', '<', $m->id)
                ->whereNotNull('unit_cost')
                ->orderByDesc('id')
                ->value('unit_cost');

            if ($cost === null) {
                $uncosted++;

                continue;
            }

            $amount -= (int) $m->quantity * (float) $cost;
            $units -= (int) $m->quantity;
        }

        return ['amount' => round($amount, 2), 'units' => $units, 'uncosted' => $uncosted];
    }
}
