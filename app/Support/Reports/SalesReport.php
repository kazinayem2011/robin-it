<?php

namespace App\Support\Reports;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What sold, when, and whether that is better or worse than before.
 *
 * The shop could see a profit-and-loss statement and nothing else, so the
 * question everybody actually asks first — "how did last month go against the
 * one before it" — had no answer anywhere.
 *
 * Cancelled and returned orders are left out throughout. They are not sales,
 * and counting them makes a bad month look like a good one.
 */
class SalesReport
{
    private const NOT_A_SALE = ['cancelled', 'returned'];

    /**
     * @return array{
     *     totals: array<string, mixed>,
     *     previous: array<string, mixed>,
     *     series: array<int, array{on:string, revenue:float, orders:int, units:int}>,
     *     by_status: array<string, int>,
     *     by_payment: array<int, array{method:string, orders:int, revenue:float}>
     * }
     */
    public static function for(string $from, string $to): array
    {
        $totals = self::totals($from, $to);

        /*
         * The same length of time immediately before, so "up 12%" means
         * something. A month against a fortnight would flatter or damn the
         * period for no reason but its length.
         */
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $previousTo = Carbon::parse($from)->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);

        return [
            'totals' => $totals,
            'previous' => self::totals($previousFrom->toDateString(), $previousTo->toDateString()),
            'previous_period' => [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
            ],
            'series' => self::series($from, $to),
            'by_status' => self::byStatus($from, $to),
            'by_payment' => self::byPayment($from, $to),
        ];
    }

    /**
     * @return array{revenue:float, orders:int, units:int, average_order:float, refunded:float, net:float}
     */
    public static function totals(string $from, string $to): array
    {
        $orders = Order::query()
            ->whereNotIn('status', self::NOT_A_SALE)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get(['id', 'subtotal', 'discount', 'vat_amount', 'vat_inclusive']);

        // Net of VAT, matching how the margin and the P&L read revenue: the
        // tax is collected for the government, not earned.
        $revenue = round($orders->sum(function ($order) {
            $goods = (float) $order->subtotal - (float) $order->discount;

            return $order->vat_inclusive ? $goods - (float) $order->vat_amount : $goods;
        }), 2);

        $units = (int) OrderItem::whereIn('order_id', $orders->pluck('id'))->sum('quantity');

        /*
         * Refunds by the date the money moved, not the date of the sale it
         * relates to: a refund in September on an August order is September's
         * problem, which is the month the shop was out of pocket.
         */
        $refunded = round((float) Refund::query()
            ->settled()
            ->between($from, $to)
            ->sum('amount'), 2);

        return [
            'revenue' => $revenue,
            'orders' => $orders->count(),
            'units' => $units,
            'average_order' => $orders->count() > 0 ? round($revenue / $orders->count(), 2) : 0.0,
            'refunded' => $refunded,
            'net' => round($revenue - $refunded, 2),
        ];
    }

    /**
     * Day by day, including the days nothing sold.
     *
     * A gap in a chart reads as missing data; a zero reads as a quiet Friday,
     * which is what it was.
     *
     * @return array<int, array{on:string, revenue:float, orders:int, units:int}>
     */
    private static function series(string $from, string $to): array
    {
        /*
         * Two queries rather than one join.
         *
         * Joining orders to their lines and summing the order total gives the
         * total once per line, and SUM(DISTINCT) does not fix it — that
         * collapses two different orders that happen to come to the same
         * amount into one. Counting units needs the join and counting money
         * must not have it, so they are asked separately.
         */
        $money = Order::query()
            ->whereNotIn('status', self::NOT_A_SALE)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get(['created_at', 'subtotal', 'discount', 'vat_amount', 'vat_inclusive'])
            ->groupBy(fn ($order) => $order->created_at->toDateString());

        $units = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', self::NOT_A_SALE)
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->groupBy('on')
            ->selectRaw('DATE(orders.created_at) as `on`, SUM(order_items.quantity) as units')
            ->pluck('units', 'on');

        $series = [];

        // Every day in the period, including the ones nothing sold on: a gap
        // in a chart reads as missing data, a zero reads as a quiet Friday.
        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $key = $day->toDateString();
            $orders = $money->get($key, collect());

            $series[] = [
                'on' => $key,
                // Net of VAT, the same way totals() and the P&L read revenue.
                'revenue' => round($orders->sum(function ($order) {
                    $goods = (float) $order->subtotal - (float) $order->discount;

                    return $order->vat_inclusive ? $goods - (float) $order->vat_amount : $goods;
                }), 2),
                'orders' => $orders->count(),
                'units' => (int) ($units[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Where the period's orders currently stand.
     *
     * Includes cancelled and returned, because this is the one place they are
     * the point: a rising cancellation count is the thing worth seeing.
     *
     * @return array<string, int>
     */
    private static function byStatus(string $from, string $to): array
    {
        return Order::query()
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * How customers paid, which is what tells a shop whether to keep offering
     * cash on delivery.
     *
     * @return array<int, array{method:string, orders:int, revenue:float}>
     */
    private static function byPayment(string $from, string $to): array
    {
        return Order::query()
            ->whereNotIn('status', self::NOT_A_SALE)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->selectRaw('payment_method, COUNT(*) as orders, SUM(total) as revenue')
            ->groupBy('payment_method')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method ?: 'Not recorded',
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }
}
