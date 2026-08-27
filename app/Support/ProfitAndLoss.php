<?php

namespace App\Support;

use App\Models\Expense;

/**
 * What the shop earned and what it spent, over a period.
 *
 * The two sides come from different places on purpose:
 *
 *   Cost of goods sold comes from the order lines, priced at what those units
 *   cost when they were sold. Not from what was bought in the period — a
 *   delivery that is still on the shelf has not cost the shop anything yet,
 *   it has only turned cash into stock.
 *
 *   Everything else comes from the expenses table: rent, wages, the courier's
 *   bill, packaging. Money that leaves and does not come back as something
 *   sellable.
 *
 * Orders whose cost was never recorded are left out of both revenue and cost
 * rather than counted on one side only, which would report their whole sale
 * price as profit. What was left out is carried on the statement so the reader
 * can see the size of the gap.
 */
class ProfitAndLoss
{
    /**
     * @return array{
     *     from:string|null, to:string|null,
     *     income:array{goods:float, delivery:float, total:float},
     *     cost_of_goods:float,
     *     gross_profit:float,
     *     gross_margin_percent:float|null,
     *     expenses:array{total:float, by_category:array<int, array{key:string, label:string, amount:float}>},
     *     net_profit:float,
     *     net_margin_percent:float|null,
     *     orders_counted:int,
     *     excluded:array{orders:int, revenue:float}
     * }
     */
    public static function statement(?string $from = null, ?string $to = null): array
    {
        $sales = SalesMargin::summary($from, $to);

        $income = round($sales['goods_revenue'] + $sales['delivery_collected'], 2);
        $expenses = self::expenses($from, $to);
        $net = round($sales['gross_profit'] + $sales['delivery_collected'] - $expenses['total'], 2);

        return [
            'from' => $from,
            'to' => $to,

            'income' => [
                'goods' => $sales['goods_revenue'],
                // Shown on its own line rather than folded into goods: the shop
                // collects it for the courier, and what the courier charges
                // sits in expenses under `delivery`. Both visible, so the
                // difference between them is too.
                'delivery' => $sales['delivery_collected'],
                'total' => $income,
            ],

            'cost_of_goods' => $sales['cost'],
            'gross_profit' => $sales['gross_profit'],
            'gross_margin_percent' => $sales['margin_percent'],

            'expenses' => $expenses,

            'net_profit' => $net,
            'net_margin_percent' => $income > 0 ? round($net / $income * 100, 1) : null,

            'orders_counted' => $sales['orders_counted'],
            'excluded' => [
                'orders' => $sales['orders_uncosted'],
                'revenue' => $sales['uncosted_revenue'],
            ],
        ];
    }

    /**
     * Spending in the period, totalled and broken down.
     *
     * Every category is listed even at zero, so a reader can tell "nothing was
     * spent on marketing" from "marketing is not a thing we track".
     *
     * @return array{total:float, by_category:array<int, array{key:string, label:string, amount:float}>}
     */
    private static function expenses(?string $from, ?string $to): array
    {
        $totals = Expense::between($from, $to)
            ->groupBy('category')
            ->selectRaw('category, SUM(amount) as total')
            ->pluck('total', 'category');

        $byCategory = [];
        $total = 0.0;

        foreach (Expense::CATEGORIES as $key => $label) {
            $amount = round((float) ($totals[$key] ?? 0), 2);
            $total += $amount;

            $byCategory[] = ['key' => $key, 'label' => $label, 'amount' => $amount];
        }

        // A category that was retired from the list but still has history must
        // not silently vanish from the total.
        foreach ($totals as $key => $amount) {
            if (! array_key_exists($key, Expense::CATEGORIES)) {
                $amount = round((float) $amount, 2);
                $total += $amount;

                $byCategory[] = ['key' => $key, 'label' => ucfirst((string) $key), 'amount' => $amount];
            }
        }

        return ['total' => round($total, 2), 'by_category' => $byCategory];
    }
}
