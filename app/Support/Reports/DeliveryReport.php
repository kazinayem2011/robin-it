<?php

namespace App\Support\Reports;

use App\Models\Courier;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Which courier actually delivers.
 *
 * A shop picks a carrier on price and finds out about the rest afterwards, one
 * angry phone call at a time. The orders table already holds the answer — who
 * carried it, when it went out, and how it ended — and nothing was reading it.
 *
 * The figure that matters is not the delivery rate on its own but the pairing:
 * a carrier delivering 95% in six days may be worse for a shop than one
 * delivering 90% in two, and neither number says that alone.
 */
class DeliveryReport
{
    /**
     * @return array{
     *     couriers:array<int, array<string, mixed>>,
     *     totals:array<string, mixed>,
     *     undispatched:int
     * }
     */
    public static function for(string $from, string $to): array
    {
        $orders = Order::query()
            ->whereNotNull('courier_id')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get(['courier_id', 'status', 'created_at', 'dispatched_at', 'updated_at', 'total']);

        $names = Courier::pluck('name', 'id');

        $couriers = $orders
            ->groupBy('courier_id')
            ->map(function ($theirs, $courierId) use ($names) {
                $delivered = $theirs->where('status', 'delivered');
                $returned = $theirs->where('status', 'returned');
                $cancelled = $theirs->where('status', 'cancelled');

                /*
                 * Only parcels that have finished their journey count towards a
                 * rate. Including the ones still in transit makes a courier look
                 * worse the busier the shop has been this week, which is a
                 * measure of the shop rather than of them.
                 */
                $settled = $delivered->count() + $returned->count();

                return [
                    'courier_id' => (int) $courierId,
                    'name' => $names[$courierId] ?? 'Removed courier',
                    'parcels' => $theirs->count(),
                    'delivered' => $delivered->count(),
                    'returned' => $returned->count(),
                    'cancelled' => $cancelled->count(),
                    'in_transit' => $theirs->whereIn('status', ['pending', 'processing', 'shipped'])->count(),
                    'delivery_rate' => $settled > 0
                        ? round($delivered->count() / $settled * 100, 1)
                        : null,
                    'return_rate' => $settled > 0
                        ? round($returned->count() / $settled * 100, 1)
                        : null,
                    'average_days' => self::averageDays($delivered),
                    'value' => round($theirs->sum(fn ($o) => (float) $o->total), 2),
                ];
            })
            ->sortByDesc('parcels')
            ->values();

        $allSettled = $orders->whereIn('status', ['delivered', 'returned']);

        return [
            'couriers' => $couriers->all(),
            'totals' => [
                'parcels' => $orders->count(),
                'delivered' => $orders->where('status', 'delivered')->count(),
                'returned' => $orders->where('status', 'returned')->count(),
                'delivery_rate' => $allSettled->count() > 0
                    ? round($orders->where('status', 'delivered')->count() / $allSettled->count() * 100, 1)
                    : null,
                'average_days' => self::averageDays($orders->where('status', 'delivered')),
            ],
            /*
             * Orders sitting in the shop with no courier attached. Not a
             * courier's fault and worth seeing on the same screen: a parcel
             * nobody has booked is the delay a customer actually feels.
             */
            'undispatched' => Order::query()
                ->whereIn('status', ['pending', 'processing'])
                ->whereNull('dispatched_at')
                ->whereDate('created_at', '>=', $from)
                ->whereDate('created_at', '<=', $to)
                ->count(),
        ];
    }

    /**
     * Days from handing a parcel over to it arriving.
     *
     * Measured from dispatch rather than from the order, because the time a
     * shop takes to pick and pack is the shop's own and blaming a courier for
     * it makes the number useless for choosing between them.
     *
     * @param  Collection<int, Order>  $delivered
     */
    private static function averageDays($delivered): ?float
    {
        $withDates = $delivered->filter(fn ($order) => $order->dispatched_at && $order->updated_at);

        if ($withDates->isEmpty()) {
            return null;
        }

        return round(
            $withDates->avg(fn ($order) => $order->dispatched_at->diffInDays($order->updated_at)),
            1
        );
    }
}
