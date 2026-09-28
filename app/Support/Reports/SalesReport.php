<?php

namespace App\Support\Reports;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * What sold, when, and whether that is better or worse than before.
 *
 * The shop could see a profit-and-loss statement and nothing else, so the
 * question everybody actually asks first — "how did last month go against the
 * one before it" — had no answer anywhere.
 *
 * A sale is what Sold says it is: delivered, on the day it was delivered, and
 * only what the customer kept. Cancelled and returned orders are not sales,
 * and counting them makes a bad month look like a good one.
 */
class SalesReport
{
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
        // Sold's rule: delivered, and only what the customer kept. Net of VAT,
        // matching how the margin and the P&L read revenue: the tax is
        // collected for the government, not earned.
        $figures = Sold::load($from, $to)->map(fn ($order) => Sold::figures($order));

        $revenue = round($figures->sum('goods'), 2);

        // Money given back beyond what came back; returned goods are already
        // off the revenue.
        $refunded = round($figures->sum('given_back'), 2);

        return [
            'revenue' => $revenue,
            'orders' => $figures->count(),
            'units' => (int) $figures->sum('units'),
            'average_order' => $figures->count() > 0 ? round($revenue / $figures->count(), 2) : 0.0,
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
        // By the day each order was delivered.
        $byDay = Sold::load($from, $to)
            ->groupBy(fn ($order) => $order->delivered_at->toDateString())
            ->map(fn ($orders) => $orders->map(fn ($order) => Sold::figures($order)));

        $series = [];

        // Every day in the period, including the ones nothing sold on: a gap
        // in a chart reads as missing data, a zero reads as a quiet Friday.
        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $key = $day->toDateString();
            $figures = $byDay->get($key, collect());

            $series[] = [
                'on' => $key,
                // Net of VAT, the same way totals() and the P&L read revenue.
                'revenue' => round($figures->sum('goods'), 2),
                'orders' => $figures->count(),
                'units' => (int) $figures->sum('units'),
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
        // What customers ended up paying, by how they paid.
        return Sold::load($from, $to)
            ->groupBy(fn ($order) => $order->payment_method ?: 'Not recorded')
            ->map(fn ($orders, $method) => [
                'method' => $method,
                'orders' => $orders->count(),
                'revenue' => round($orders->sum(fn ($order) => Sold::figures($order)['spent']), 2),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }
}
