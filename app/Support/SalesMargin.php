<?php

namespace App\Support;

use App\Support\Reports\Sold;

/**
 * Gross margin on the orders whose cost is actually known.
 *
 * This is not a profit-and-loss statement — see ProfitAndLoss for that, which
 * builds on this and adds the expense side. What this says is what the goods
 * sold for, less what those goods cost.
 *
 * What counts as sold is Sold's rule: delivered, on the day it was delivered,
 * and only what the customer kept.
 *
 * Orders with any uncosted line are left out entirely rather than counted at a
 * partial cost, because a partial cost reads as profit that is not there. How
 * many were left out, and what they were worth, is reported alongside so the
 * figure is never mistaken for the whole picture — the same rule the stock
 * valuation follows.
 */
class SalesMargin
{
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
        $figures = Sold::load($from, $to)->map(fn ($order) => Sold::figures($order));

        [$costed, $uncosted] = $figures->partition(fn ($f) => $f['cost'] !== null);

        // Revenue is net of VAT: the tax is collected for the government and
        // owed to it, so counting it as income would overstate both revenue
        // and profit by the rate, every period. Goods and delivery are kept
        // apart: what the courier charges the shop is an expense of its own.
        $goods = round($costed->sum('goods'), 2);
        $cost = round($costed->sum('cost'), 2);

        // Money given back beyond what came back. What came back is already
        // off the goods, so only the rest is taken off here.
        $refunded = round($costed->sum('given_back'), 2);
        $profit = round($goods - $refunded - $cost, 2);

        return [
            'goods_revenue' => $goods,
            'refunded' => $refunded,
            'vat_collected' => round($costed->sum('vat'), 2),
            'delivery_collected' => round($costed->sum('delivery'), 2),
            'cost' => $cost,
            'gross_profit' => $profit,
            'margin_percent' => $goods > 0 ? round($profit / $goods * 100, 1) : null,
            'orders_counted' => $costed->count(),
            'orders_uncosted' => $uncosted->count(),
            'uncosted_revenue' => round($uncosted->sum('goods'), 2),
        ];
    }
}
