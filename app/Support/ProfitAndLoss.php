<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\Reports\Sold;

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
 *   Stock that left without being sold — damaged returns, write-offs, a
 *   stock count that came up short — is a cost of its own, on its own line.
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
     *     income:array{goods:float, given_back:float, delivery:float, total:float},
     *     vat_collected:float,
     *     refunded:float,
     *     cost_of_goods:float,
     *     stock_lost:array{amount:float, units:int, uncosted:int},
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

        $income = round($sales['goods_revenue'] - $sales['refunded'] + $sales['delivery_collected'], 2);
        $lost = Sold::stockLost($from, $to);
        $expenses = self::expenses($from, $to);

        // Straight down the statement, so the lines on the page add up to it:
        // income, less what the goods cost, less the stock lost.
        $gross = round($income - $sales['cost'] - $lost['amount'], 2);
        $net = round($gross - $expenses['total'], 2);

        return [
            'from' => $from,
            'to' => $to,

            'income' => [
                'goods' => $sales['goods_revenue'],
                // Money given back on top of what came back — a goodwill
                // refund with nothing returned. Returned goods are already off
                // the goods figure.
                'given_back' => $sales['refunded'],
                // Shown on its own line rather than folded into goods: the shop
                // collects it for the courier, and what the courier charges
                // sits in expenses under `delivery`. Both visible, so the
                // difference between them is too.
                'delivery' => $sales['delivery_collected'],
                'total' => $income,
            ],

            /*
             * Shown, but never added in. VAT is collected for the government
             * and owed to it, so it is neither income nor an expense — it is
             * money passing through. It appears so the figure can be checked
             * against what is being remitted.
             */
            'vat_collected' => $sales['vat_collected'],

            'refunded' => $sales['refunded'],

            'cost_of_goods' => $sales['cost'],
            'stock_lost' => $lost,
            'gross_profit' => $gross,
            'gross_margin_percent' => $income > 0 ? round($gross / $income * 100, 1) : null,

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
            ->groupBy('expense_category_id')
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->pluck('total', 'expense_category_id');

        $byCategory = [];
        $total = 0.0;

        // Every category, even at zero, so "nothing was spent on marketing" is
        // distinguishable from "marketing is not something we track".
        foreach (ExpenseCategory::ordered()->get() as $category) {
            $amount = round((float) ($totals[$category->id] ?? 0), 2);
            $total += $amount;

            // A retired category with no spending in the period is noise; one
            // with spending still has to be accounted for.
            if (! $category->is_active && $amount <= 0) {
                continue;
            }

            $byCategory[] = ['key' => $category->slug, 'label' => $category->name, 'amount' => $amount];
        }

        /*
         * Spending whose category was deleted outright. The money left the
         * business whatever happened to the label, so it is carried rather
         * than quietly dropped from the total.
         */
        // Looked up by '' rather than by null: a null group key becomes an
        // empty string once it is an array key, and PHP 8 deprecates using
        // null as an offset — which this did, on every view of the report.
        $orphaned = round((float) ($totals->get('') ?? 0), 2);

        if ($orphaned > 0) {
            $total += $orphaned;
            $byCategory[] = ['key' => 'uncategorised', 'label' => 'Uncategorised', 'amount' => $orphaned];
        }

        return ['total' => round($total, 2), 'by_category' => $byCategory];
    }
}
