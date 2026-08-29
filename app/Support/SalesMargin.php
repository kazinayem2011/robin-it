<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Gross margin on the orders whose cost is actually known.
 *
 * This is not a profit-and-loss statement — see ProfitAndLoss for that, which
 * builds on this and adds the expense side. What this says is what the goods
 * sold for, less what those goods cost.
 *
 * Orders with any uncosted line are left out entirely rather than counted at a
 * partial cost, because a partial cost reads as profit that is not there. How
 * many were left out, and what they were worth, is reported alongside so the
 * figure is never mistaken for the whole picture — the same rule the stock
 * valuation follows.
 */
class SalesMargin
{
    /** Orders in these states never happened as far as the accounts go. */
    private const EXCLUDED_STATUSES = ['cancelled', 'returned'];

    /**
     * @param  string|null  $from  inclusive date, or null for "since the start"
     * @param  string|null  $to  inclusive date, or null for "up to now"
     * @return array{
     *     goods_revenue:float, refunded:float, vat_collected:float,
     *     delivery_collected:float, cost:float,
     *     gross_profit:float, margin_percent:float|null,
     *     orders_counted:int, orders_uncosted:int, uncosted_revenue:float
     * }
     */
    public static function summary(?string $from = null, ?string $to = null): array
    {
        $costed = self::costedOrders($from, $to)->get();

        // Goods and delivery are kept apart: the delivery fee is collected on
        // the courier's behalf, and what the courier charges the shop is an
        // expense recorded separately. Netting them here would hide both.
        /*
         * Revenue is net of VAT. The tax is collected for the government and
         * owed to it — counting it as income would overstate both revenue and
         * profit by the rate, every period.
         *
         * With inclusive pricing it sits inside the goods figure and is taken
         * out; with exclusive pricing it was never in there, and the stored
         * amount is what was added on top.
         */
        $goods = round($costed->sum(function ($row) {
            $gross = (float) $row->subtotal - (float) $row->discount;

            return $row->vat_inclusive ? $gross - (float) $row->vat_amount : $gross;
        }), 2);

        $vat = round($costed->sum(fn ($row) => (float) $row->vat_amount), 2);
        $delivery = round($costed->sum(fn ($row) => (float) $row->shipping_fee), 2);
        $cost = round($costed->sum(fn ($row) => (float) $row->cost_of_goods), 2);

        /*
         * Refunds are revenue handed back, so they come off it.
         *
         * Counted by the date the money moved rather than by the order's date:
         * a refund in September on an August sale belongs to September, which
         * is the month the shop was actually out of pocket.
         *
         * `settled` leaves out cash that was never collected — on a
         * cash-on-delivery order that came back, nothing was ever taken, and
         * the sale it relates to is already excluded by its status.
         */
        $refunded = round((float) Refund::query()
            ->settled()
            ->between($from, $to)
            ->sum('amount'), 2);

        $profit = round($goods - $cost - $refunded, 2);

        $uncosted = self::uncostedOrders($from, $to, $costed->pluck('id')->all());

        return [
            'goods_revenue' => $goods,
            'refunded' => $refunded,
            'vat_collected' => $vat,
            'delivery_collected' => $delivery,
            'cost' => $cost,
            'gross_profit' => $profit,
            'margin_percent' => $goods > 0 ? round($profit / $goods * 100, 1) : null,
            'orders_counted' => $costed->count(),
            'orders_uncosted' => $uncosted['count'],
            'uncosted_revenue' => $uncosted['revenue'],
        ];
    }

    /** One row per order that has a cost recorded against every line. */
    private static function costedOrders(?string $from, ?string $to): QueryBuilder
    {
        return DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereNotIn('orders.status', self::EXCLUDED_STATUSES)
            ->when($from, fn ($q) => $q->whereDate('orders.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('orders.created_at', '<=', $to))
            ->groupBy(
                'orders.id', 'orders.subtotal', 'orders.discount',
                'orders.shipping_fee', 'orders.vat_amount', 'orders.vat_inclusive'
            )
            ->havingRaw('SUM(CASE WHEN order_items.unit_cost IS NULL THEN 1 ELSE 0 END) = 0')
            ->select([
                'orders.id',
                'orders.subtotal',
                'orders.discount',
                'orders.shipping_fee',
                'orders.vat_amount',
                'orders.vat_inclusive',
                DB::raw('SUM(order_items.unit_cost * order_items.quantity) AS cost_of_goods'),
            ]);
    }

    /**
     * What is missing from the figures above, so the gap is visible rather
     * than silently absorbed.
     *
     * Defined as "every live order the costed set did not pick up", rather than
     * by asking which orders have an uncosted line. Those are not the same
     * question, and the difference is a hole: an order with no lines at all
     * has no line without a cost, so it answered no to both — the inner join
     * dropped it from the costed figure and this dropped it from the warning
     * about the costed figure. It appeared nowhere, and the notice that exists
     * to say what is missing was itself missing it.
     *
     * Subtracting one set from the other makes counted + uncosted equal the
     * number of live orders by construction, whatever shape the data is in.
     *
     * @param  array<int, int>  $costedIds  orders already counted in the figures
     * @return array{count:int, revenue:float}
     */
    private static function uncostedOrders(?string $from, ?string $to, array $costedIds): array
    {
        $orders = Order::query()
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->whereNotIn('id', $costedIds)
            ->get(['subtotal', 'discount', 'vat_amount', 'vat_inclusive']);

        return [
            'count' => $orders->count(),
            // Net of VAT as well, so the excluded figure is comparable with
            // the revenue it is missing from.
            'revenue' => round($orders->sum(function ($o) {
                $gross = (float) $o->subtotal - (float) $o->discount;

                return $o->vat_inclusive ? $gross - (float) $o->vat_amount : $gross;
            }), 2),
        ];
    }
}
