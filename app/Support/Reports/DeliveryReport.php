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
            ->get(['courier_id', 'status', 'created_at', 'dispatched_at', 'delivered_at', 'updated_at', 'total']);

        $names = Courier::pluck('name', 'id');

        $couriers = $orders
            ->groupBy('courier_id')
            ->map(function ($theirs, $courierId) use ($names) {
                $delivered = $theirs->filter(fn ($o) => self::reached($o));
                $returned = $theirs->filter(fn ($o) => self::cameBack($o));
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

        $reached = $orders->filter(fn ($o) => self::reached($o));
        $allSettled = $reached->merge($orders->filter(fn ($o) => self::cameBack($o)));

        return [
            'couriers' => $couriers->all(),
            'totals' => [
                'parcels' => $orders->count(),
                'delivered' => $reached->count(),
                'returned' => $allSettled->count() - $reached->count(),
                'delivery_rate' => $allSettled->count() > 0
                    ? round($reached->count() / $allSettled->count() * 100, 1)
                    : null,
                'average_days' => self::averageDays($reached),
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
     * The parcel reached the customer.
     *
     * Including an order the customer later sent back: the courier delivered
     * it, and what the customer did afterwards is not the courier's doing.
     * Counting it against them made a carrier look worse for the shop's own
     * returns.
     */
    private static function reached(Order $order): bool
    {
        return $order->status === 'delivered'
            || ($order->status === 'returned' && $order->delivered_at !== null);
    }

    /** Came back without ever reaching the customer — refused, or not found. */
    private static function cameBack(Order $order): bool
    {
        return $order->status === 'returned' && $order->delivered_at === null;
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
        // To the day it was delivered. It was the last time the order
        // changed, so a payment recorded a week later made the courier a
        // week slower.
        $arrived = fn ($order) => $order->delivered_at ?? $order->updated_at;
        $withDates = $delivered->filter(fn ($order) => $order->dispatched_at && $arrived($order));

        if ($withDates->isEmpty()) {
            return null;
        }

        return round(
            $withDates->avg(fn ($order) => $order->dispatched_at->diffInDays($arrived($order))),
            1
        );
    }
}
