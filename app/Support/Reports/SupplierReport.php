<?php

namespace App\Support\Reports;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which suppliers send what they promised, when they promised it.
 *
 * Only answerable since purchase orders existed: receipts recorded what turned
 * up, and without a record of what was asked for, a supplier who habitually
 * ships eighteen against twenty looked exactly like one who ships twenty.
 */
class SupplierReport
{
    /**
     * @return array{
     *     suppliers:array<int, array<string, mixed>>,
     *     totals:array<string, mixed>,
     *     outstanding:array<int, array<string, mixed>>
     * }
     */
    public static function for(string $from, string $to): array
    {
        $orders = PurchaseOrder::query()
            ->with(['items', 'receipts:id,purchase_order_id,received_on'])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get();

        $suppliers = $orders
            ->groupBy('supplier_id')
            ->map(function ($theirs, $supplierId) {
                $finished = $theirs->whereIn('status', [PurchaseOrder::RECEIVED, PurchaseOrder::PARTIAL]);

                $ordered = $theirs->sum(fn ($o) => $o->items->sum('quantity'));
                $received = $theirs->sum(fn ($o) => $o->items->sum('quantity_received'));

                return [
                    'supplier_id' => (int) $supplierId,
                    'name' => $theirs->first()->supplier_name
                        ?? Supplier::find($supplierId)?->name
                        ?? 'Removed supplier',
                    'orders' => $theirs->count(),
                    'units_ordered' => (int) $ordered,
                    'units_received' => (int) $received,
                    'still_owed' => (int) max(0, $ordered - $received),
                    /*
                     * How much of what was asked for actually arrived. The
                     * single number worth comparing suppliers on, and the one
                     * nothing could answer before purchase orders existed.
                     */
                    'fill_rate' => $ordered > 0 ? round($received / $ordered * 100, 1) : null,
                    'value' => round($theirs->sum(fn ($o) => (float) $o->total_cost), 2),
                    'average_days_late' => self::averageDaysLate($finished),
                    'cancelled' => $theirs->where('status', PurchaseOrder::CANCELLED)->count(),
                ];
            })
            ->sortByDesc('value')
            ->values();

        $allOrdered = $orders->sum(fn ($o) => $o->items->sum('quantity'));
        $allReceived = $orders->sum(fn ($o) => $o->items->sum('quantity_received'));

        return [
            'suppliers' => $suppliers->all(),
            'totals' => [
                'orders' => $orders->count(),
                'units_ordered' => (int) $allOrdered,
                'units_received' => (int) $allReceived,
                'value' => round($orders->sum(fn ($o) => (float) $o->total_cost), 2),
                'fill_rate' => $allOrdered > 0 ? round($allReceived / $allOrdered * 100, 1) : null,
            ],
            // What is genuinely still coming, whenever it was ordered.
            'outstanding' => PurchaseOrder::query()
                ->open()
                ->with(['items'])
                ->get()
                ->map(fn ($order) => [
                    'reference' => $order->reference,
                    'supplier' => $order->supplier_name,
                    'expected_on' => $order->expected_on?->toDateString(),
                    'days_overdue' => $order->expected_on && $order->expected_on->isPast()
                        ? (int) $order->expected_on->diffInDays(now())
                        : 0,
                    'outstanding' => $order->outstanding,
                ])
                ->sortByDesc('days_overdue')
                ->values()
                ->all(),
        ];
    }

    /**
     * How late a delivery ran against the date it was promised for.
     *
     * Orders with no expected date are left out rather than counted as on
     * time: nobody promised anything, so there is nothing to be late against.
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     */
    private static function averageDaysLate($orders): ?float
    {
        $measurable = $orders->filter(
            fn ($order) => $order->expected_on && $order->receipts->isNotEmpty()
        );

        if ($measurable->isEmpty()) {
            return null;
        }

        return round($measurable->avg(function ($order) {
            $arrived = Carbon::parse($order->receipts->min('received_on'));

            // Early is zero rather than negative: a supplier who is sometimes
            // early and sometimes very late should not average out to on time.
            return max(0, $order->expected_on->diffInDays($arrived, false));
        }), 1);
    }
}
