<?php

namespace App\Support\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Which products are worth their shelf space.
 *
 * Ranked by what they earned, not by how many left the building. A cable
 * selling two hundred times at forty taka of margin is worth less than one
 * graphics card, and a list ordered by units puts the cable at the top and
 * quietly recommends buying more of them.
 */
class ProductReport
{
    private const NOT_A_SALE = ['cancelled', 'returned'];

    /**
     * @return array{
     *     lines:array<int, array<string, mixed>>,
     *     uncosted:int,
     *     totals:array{revenue:float, cost:float, profit:float, units:int}
     * }
     */
    public static function for(string $from, string $to, int $limit = 100): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereNotIn('orders.status', self::NOT_A_SALE)
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->groupBy('order_items.product_id', 'order_items.product_name', 'products.stock_quantity')
            ->select([
                'order_items.product_id',
                'order_items.product_name',
                'products.stock_quantity',
            ])
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.total) as revenue')
            // Null unit costs make the sum wrong rather than zero, so they are
            // counted separately and the margin is withheld for those lines.
            ->selectRaw('SUM(CASE WHEN order_items.unit_cost IS NULL THEN 1 ELSE 0 END) as uncosted_lines')
            ->selectRaw('SUM(COALESCE(order_items.unit_cost, 0) * order_items.quantity) as cost')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders')
            ->get();

        $lines = $rows->map(function ($row) {
            $revenue = round((float) $row->revenue, 2);
            $costed = (int) $row->uncosted_lines === 0;
            $cost = $costed ? round((float) $row->cost, 2) : null;

            return [
                'product_id' => $row->product_id,
                'name' => $row->product_name,
                'units' => (int) $row->units,
                'orders' => (int) $row->orders,
                'revenue' => $revenue,
                'cost' => $cost,
                // Withheld rather than guessed: a partial cost reads as profit
                // that is not there, which is the same rule the margin follows.
                'profit' => $cost !== null ? round($revenue - $cost, 2) : null,
                'margin_percent' => $cost !== null && $revenue > 0
                    ? round(($revenue - $cost) / $revenue * 100, 1)
                    : null,
                'in_stock' => $row->stock_quantity === null ? null : (int) $row->stock_quantity,
            ];
        })
            /*
             * By profit where it is known, and by revenue where it is not, so a
             * product nobody costed still appears somewhere sensible instead of
             * falling to the bottom as if it earned nothing.
             */
            ->sortByDesc(fn ($line) => $line['profit'] ?? $line['revenue'])
            ->take($limit)
            ->values();

        return [
            'lines' => $lines->all(),
            'uncosted' => $rows->filter(fn ($r) => (int) $r->uncosted_lines > 0)->count(),
            'totals' => [
                'revenue' => round($rows->sum(fn ($r) => (float) $r->revenue), 2),
                'cost' => round($rows->sum(fn ($r) => (float) $r->cost), 2),
                'profit' => round(
                    $rows->sum(fn ($r) => (int) $r->uncosted_lines === 0
                        ? (float) $r->revenue - (float) $r->cost
                        : 0),
                    2
                ),
                'units' => (int) $rows->sum('units'),
            ],
        ];
    }

    /**
     * Listed, in stock, and not sold once in the period.
     *
     * The other half of the question. A best-seller list tells a shop what to
     * reorder; this tells it what to stop buying, which is the more expensive
     * mistake and the one nobody goes looking for.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function neverSold(string $from, string $to): array
    {
        $sold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', self::NOT_A_SALE)
            ->whereDate('orders.created_at', '>=', $from)
            ->whereDate('orders.created_at', '<=', $to)
            ->distinct()
            ->pluck('order_items.product_id');

        return DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.is_active', true)
            ->where('products.stock_quantity', '>', 0)
            ->whereNotIn('products.id', $sold)
            ->orderByDesc('products.stock_quantity')
            ->limit(100)
            ->get([
                'products.id',
                'products.name',
                'products.stock_quantity as in_stock',
                'products.price',
                'categories.name as category',
            ])
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'category' => $row->category ?: 'Uncategorised',
                'in_stock' => (int) $row->in_stock,
                'price' => (float) $row->price,
                // At the shelf price, not at cost — the question here is how
                // much sellable stock is sitting still.
                'tied_up' => round((float) $row->price * (int) $row->in_stock, 2),
            ])
            ->all();
    }
}
