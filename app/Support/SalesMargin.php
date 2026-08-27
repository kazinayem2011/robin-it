<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Gross margin on the orders whose cost is actually known.
 *
 * This is not a profit-and-loss statement and must not be read as one: the
 * shop has no expense records yet, so rent, wages, packaging and the courier's
 * own bill are all missing. What it does say is what the goods sold for less
 * what those goods cost, which is the first honest number available now that
 * order lines carry the price the shop paid.
 *
 * Orders with any uncosted line are left out entirely rather than counted at a
 * partial cost, because a partial cost reads as profit that is not there. How
 * many were left out is reported alongside, so the figure is never mistaken
 * for the whole picture — the same rule the stock valuation follows.
 */
class SalesMargin
{
    /**
     * @return array{
     *     revenue:float, cost:float, gross_profit:float, margin_percent:float|null,
     *     orders_counted:int, orders_uncosted:int
     * }
     */
    public static function summary(): array
    {
        $costed = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereNotIn('orders.status', ['cancelled', 'returned'])
            ->groupBy('orders.id', 'orders.subtotal', 'orders.discount')
            ->havingRaw('SUM(CASE WHEN order_items.unit_cost IS NULL THEN 1 ELSE 0 END) = 0')
            ->select([
                'orders.id',
                'orders.subtotal',
                'orders.discount',
                DB::raw('SUM(order_items.unit_cost * order_items.quantity) AS cost_of_goods'),
            ])
            ->get();

        // Goods only. The delivery fee is collected for the courier and paying
        // them is an expense nothing records yet, so counting it as income here
        // would overstate the result.
        $revenue = round($costed->sum(fn ($row) => (float) $row->subtotal - (float) $row->discount), 2);
        $cost = round($costed->sum(fn ($row) => (float) $row->cost_of_goods), 2);
        $profit = round($revenue - $cost, 2);

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'gross_profit' => $profit,
            'margin_percent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            'orders_counted' => $costed->count(),
            'orders_uncosted' => self::uncostedOrderCount(),
        ];
    }

    /** Orders left out because at least one line has no known cost. */
    private static function uncostedOrderCount(): int
    {
        return Order::query()
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->whereHas('items', fn ($q) => $q->whereNull('unit_cost'))
            ->count();
    }
}
