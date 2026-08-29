<?php

namespace App\Support\Reports;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the shop is holding, what it is worth, and how long it has sat there.
 *
 * The second question matters more here than in most trades. Computer parts
 * depreciate on a schedule nobody controls: a graphics card bought at the
 * launch price is worth less every month it stays on the shelf, and the shop
 * finds out at the point of finally discounting it. Money tied up in stock that
 * has not moved in six months is the most expensive thing a small shop owns and
 * the least visible.
 */
class StockReport
{
    /**
     * What is on the shelves, as rows to value or age.
     *
     * Two different questions, so two different sources. Naming a branch means
     * the per-branch ledger; asking about the shop as a whole means the cached
     * balances on the products themselves — which is what the Stock screen
     * already values, and which includes stock received before anybody assigned
     * it to a showroom. Reading product_stock for the shop-wide figure would
     * silently omit exactly that.
     *
     * @return Collection<int, object>
     */
    private static function held(?int $storeId)
    {
        if ($storeId) {
            return DB::table('product_stock')
                ->join('products', 'products.id', '=', 'product_stock.product_id')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'product_stock.product_variant_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->leftJoin('stores', 'stores.id', '=', 'product_stock.store_id')
                ->where('product_stock.store_id', $storeId)
                ->where('product_stock.quantity', '>', 0)
                ->select([
                    'product_stock.product_id',
                    'product_stock.product_variant_id',
                    'product_stock.quantity',
                    'products.name as product',
                    'product_variants.name as variant',
                    'categories.name as category',
                    'stores.name as branch',
                ])
                ->get();
        }

        $products = DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.has_variants', false)
            ->where('products.stock_quantity', '>', 0)
            ->select([
                'products.id as product_id',
                DB::raw('NULL as product_variant_id'),
                'products.stock_quantity as quantity',
                'products.name as product',
                DB::raw('NULL as variant'),
                'categories.name as category',
                DB::raw("'All branches' as branch"),
            ]);

        return DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('product_variants.stock_quantity', '>', 0)
            ->select([
                'product_variants.product_id',
                'product_variants.id as product_variant_id',
                'product_variants.stock_quantity as quantity',
                'products.name as product',
                'product_variants.name as variant',
                'categories.name as category',
                DB::raw("'All branches' as branch"),
            ])
            ->union($products)
            ->get();
    }

    /**
     * What is on the shelves, at cost.
     *
     * @return array{
     *     total_units:int, total_value:float, lines:int, uncosted_lines:int,
     *     by_category:array<int, array<string, mixed>>,
     *     by_branch:array<int, array<string, mixed>>
     * }
     */
    public static function valuation(?int $storeId = null): array
    {
        $costs = app(StockService::class)->latestUnitCosts();

        $rows = self::held($storeId);

        $units = 0;
        $value = 0.0;
        $uncosted = 0;
        $byCategory = [];
        $byBranch = [];

        foreach ($rows as $row) {
            $cost = $costs[$row->product_id.':'.($row->product_variant_id ?: '')] ?? null;
            $quantity = (int) $row->quantity;
            $units += $quantity;

            /*
             * A line with no recorded cost is counted in the units and left out
             * of the money, and said so plainly. Valuing it at zero would
             * understate the shelf; guessing would be worse.
             */
            if ($cost === null) {
                $uncosted++;

                continue;
            }

            $lineValue = $cost * $quantity;
            $value += $lineValue;

            $category = $row->category ?: 'Uncategorised';
            $branch = $row->branch ?: 'Unassigned';

            $byCategory[$category] ??= ['name' => $category, 'units' => 0, 'value' => 0.0];
            $byCategory[$category]['units'] += $quantity;
            $byCategory[$category]['value'] += $lineValue;

            $byBranch[$branch] ??= ['name' => $branch, 'units' => 0, 'value' => 0.0];
            $byBranch[$branch]['units'] += $quantity;
            $byBranch[$branch]['value'] += $lineValue;
        }

        $tidy = fn (array $groups) => collect($groups)
            ->map(fn ($g) => ['name' => $g['name'], 'units' => $g['units'], 'value' => round($g['value'], 2)])
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'total_units' => $units,
            'total_value' => round($value, 2),
            'lines' => $rows->count(),
            'uncosted_lines' => $uncosted,
            'by_category' => $tidy($byCategory),
            'by_branch' => $tidy($byBranch),
        ];
    }

    /**
     * Stock that is not moving, and what it is costing to hold.
     *
     * "Last sold" comes from the ledger rather than from the orders table,
     * because the ledger is the thing that knows a unit left the building —
     * including the ones that left as a write-off or a transfer, which are not
     * sales but are movement, and a line that moved last week is not dead.
     *
     * @return array{
     *     lines:array<int, array<string, mixed>>,
     *     buckets:array<int, array{label:string, lines:int, units:int, value:float}>,
     *     total_value:float
     * }
     */
    public static function ageing(int $slowAfterDays = 60, ?int $storeId = null): array
    {
        $costs = app(StockService::class)->latestUnitCosts();

        // The last time anything at all happened to each unit, and the last
        // time one actually sold. A product with stock and no sale ever is the
        // worst case and has to be visible rather than absent.
        $lastMovement = StockMovement::query()
            ->selectRaw('product_id, product_variant_id, MAX(created_at) as at')
            ->groupBy('product_id', 'product_variant_id')
            ->get()
            ->keyBy(fn ($m) => $m->product_id.':'.($m->product_variant_id ?: ''));

        $lastSale = StockMovement::query()
            ->where('type', StockMovement::SALE)
            ->selectRaw('product_id, product_variant_id, MAX(created_at) as at')
            ->groupBy('product_id', 'product_variant_id')
            ->get()
            ->keyBy(fn ($m) => $m->product_id.':'.($m->product_variant_id ?: ''));

        $firstIn = StockMovement::query()
            ->whereIn('type', [StockMovement::PURCHASE, StockMovement::OPENING])
            ->selectRaw('product_id, product_variant_id, MIN(created_at) as at')
            ->groupBy('product_id', 'product_variant_id')
            ->get()
            ->keyBy(fn ($m) => $m->product_id.':'.($m->product_variant_id ?: ''));

        $lines = self::held($storeId)
            ->map(function ($row) use ($costs, $lastSale, $firstIn, $lastMovement) {
                $key = $row->product_id.':'.($row->product_variant_id ?: '');
                $cost = $costs[$key] ?? null;
                $quantity = (int) $row->quantity;

                $sold = $lastSale->get($key)?->at;
                $received = $firstIn->get($key)?->at;
                $moved = $lastMovement->get($key)?->at;

                /*
                 * Days since the last sale. Where nothing has ever sold, count
                 * from when it arrived instead — a part that came in yesterday
                 * and has not sold is not dead stock, and treating "never sold"
                 * as infinitely old would put every new arrival at the top.
                 */
                $since = $sold ?: $received ?: $moved;

                return [
                    'product_id' => $row->product_id,
                    'product_variant_id' => $row->product_variant_id,
                    'name' => $row->variant ? "{$row->product} ({$row->variant})" : $row->product,
                    'quantity' => $quantity,
                    'unit_cost' => $cost,
                    'value' => $cost !== null ? round($cost * $quantity, 2) : null,
                    'last_sold' => $sold ? Carbon::parse($sold)->toDateString() : null,
                    'received_on' => $received ? Carbon::parse($received)->toDateString() : null,
                    'days_idle' => $since ? (int) Carbon::parse($since)->diffInDays(now()) : null,
                    'ever_sold' => $sold !== null,
                ];
            })
            ->sortByDesc(fn ($line) => $line['days_idle'] ?? PHP_INT_MAX)
            ->values();

        return [
            'lines' => $lines->all(),
            'buckets' => self::buckets($lines),
            'total_value' => round($lines->sum(fn ($l) => $l['value'] ?? 0), 2),
            'slow_after_days' => $slowAfterDays,
            'slow_value' => round(
                $lines->filter(fn ($l) => ($l['days_idle'] ?? 0) >= $slowAfterDays)->sum(fn ($l) => $l['value'] ?? 0),
                2
            ),
        ];
    }

    /**
     * How the money splits by how long it has been sitting.
     *
     * The bands are months rather than a single "slow" flag, because a part
     * that has not moved in three months is a discount and one that has not
     * moved in a year is a mistake, and they need different answers.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<int, array{label:string, lines:int, units:int, value:float}>
     */
    private static function buckets($lines): array
    {
        $bands = [
            ['label' => 'Moved this month', 'from' => 0, 'to' => 30],
            ['label' => '1 to 3 months', 'from' => 31, 'to' => 90],
            ['label' => '3 to 6 months', 'from' => 91, 'to' => 180],
            ['label' => 'Over 6 months', 'from' => 181, 'to' => PHP_INT_MAX],
        ];

        return collect($bands)->map(function ($band) use ($lines) {
            $in = $lines->filter(function ($line) use ($band) {
                $days = $line['days_idle'] ?? 0;

                return $days >= $band['from'] && $days <= $band['to'];
            });

            return [
                'label' => $band['label'],
                'lines' => $in->count(),
                'units' => (int) $in->sum('quantity'),
                'value' => round($in->sum(fn ($l) => $l['value'] ?? 0), 2),
            ];
        })->all();
    }

    /** Products listed for sale with nothing behind them. */
    public static function outOfStock(): int
    {
        return Product::where('is_active', true)->where('stock_quantity', '<=', 0)->count();
    }
}
