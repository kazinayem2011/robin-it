<?php

namespace App\Support\Reports;

use App\Models\Order;
use App\Models\Refund;
use App\Support\VatRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cash: what is still owed, what is owed to the government, and what went back.
 *
 * A shop taking deposits and cash on delivery is lending money to its own
 * orders. The total is invisible on any screen: each order knows what it is
 * owed, and nothing adds them up. On a bad month that figure is the difference
 * between paying suppliers and not.
 */
class MoneyReport
{
    /** Orders whose money is still in play. */
    private const OPEN = ['pending', 'processing', 'shipped', 'delivered'];

    /**
     * What customers still owe, and how long they have owed it.
     *
     * @return array{
     *     total:float, orders:int,
     *     buckets:array<int, array{label:string, orders:int, amount:float}>,
     *     with_courier:array{orders:int, amount:float},
     *     lines:array<int, array<string, mixed>>
     * }
     */
    public static function owed(): array
    {
        $orders = Order::query()
            ->with('courier:id,name')
            ->whereIn('status', self::OPEN)
            ->where('payment_status', '!=', 'refunded')
            ->get();

        $owing = $orders
            ->filter(fn (Order $order) => $order->amount_due > 0.009)
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->recipient_name,
                'status' => $order->status,
                'total' => (float) $order->total,
                'paid' => $order->amount_paid,
                'due' => $order->amount_due,
                'days' => (int) $order->created_at->diffInDays(now()),
                'courier' => $order->courier?->name,
                // Dispatched and unpaid is money physically out of the shop.
                'with_courier' => $order->status === 'shipped',
            ])
            ->sortByDesc('days')
            ->values();

        $withCourier = $owing->where('with_courier', true);

        return [
            'total' => round($owing->sum('due'), 2),
            'orders' => $owing->count(),
            'buckets' => self::ageBuckets($owing),
            /*
             * Called out separately because it is the riskiest kind: the goods
             * have left, the customer has not paid, and the money is sitting
             * with a third party until they settle.
             */
            'with_courier' => [
                'orders' => $withCourier->count(),
                'amount' => round($withCourier->sum('due'), 2),
            ],
            'lines' => $owing->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $owing
     * @return array<int, array{label:string, orders:int, amount:float}>
     */
    private static function ageBuckets($owing): array
    {
        $bands = [
            ['label' => 'This week', 'from' => 0, 'to' => 7],
            ['label' => '1 to 4 weeks', 'from' => 8, 'to' => 30],
            ['label' => '1 to 3 months', 'from' => 31, 'to' => 90],
            ['label' => 'Over 3 months', 'from' => 91, 'to' => PHP_INT_MAX],
        ];

        return collect($bands)->map(function ($band) use ($owing) {
            $in = $owing->filter(
                fn ($line) => $line['days'] >= $band['from'] && $line['days'] <= $band['to']
            );

            return [
                'label' => $band['label'],
                'orders' => $in->count(),
                'amount' => round($in->sum('due'), 2),
            ];
        })->all();
    }

    /**
     * VAT collected in a period, for the return.
     *
     * Separate from the profit and loss, where VAT appears only as a note. This
     * is the figure somebody copies onto a form, so it is broken down by month
     * and states plainly that the tax is money passing through rather than
     * income.
     *
     * @return array{
     *     enabled:bool, rate:float|null, registration:string|null,
     *     collected:float, refunded:float, net:float,
     *     by_month:array<int, array{month:string, goods:float, vat:float, orders:int}>
     * }
     */
    public static function vat(string $from, string $to): array
    {
        $orders = Order::query()
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get(['created_at', 'subtotal', 'discount', 'vat_amount', 'vat_inclusive']);

        $byMonth = $orders
            ->groupBy(fn ($order) => $order->created_at->format('Y-m'))
            ->map(fn ($month, $key) => [
                'month' => $key,
                'goods' => round($month->sum(function ($order) {
                    $goods = (float) $order->subtotal - (float) $order->discount;

                    return $order->vat_inclusive ? $goods - (float) $order->vat_amount : $goods;
                }), 2),
                'vat' => round($month->sum(fn ($o) => (float) $o->vat_amount), 2),
                'orders' => $month->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        $collected = round($orders->sum(fn ($o) => (float) $o->vat_amount), 2);

        /*
         * VAT on refunded sales is reclaimable, so it comes off what is owed —
         * but only the VAT that was actually charged on the order being
         * refunded.
         *
         * The obvious version applies the current rate to the refunded amount,
         * which is wrong twice: it claims tax back on orders that never carried
         * any, and it uses today's rate on a sale made under a different one.
         * Locally that produced a bill of minus fifty-eight thousand taka
         * against nothing collected.
         *
         * A partial refund reclaims its share: half the order back is half its
         * VAT back.
         */
        $refundedVat = round(
            Refund::query()
                ->settled()
                ->between($from, $to)
                ->with('order:id,total,vat_amount')
                ->get()
                ->sum(function (Refund $refund) {
                    $order = $refund->order;

                    if (! $order || (float) $order->total <= 0 || (float) $order->vat_amount <= 0) {
                        return 0.0;
                    }

                    $share = min(1.0, (float) $refund->amount / (float) $order->total);

                    return $share * (float) $order->vat_amount;
                }),
            2
        );

        return [
            'enabled' => VatRules::enabled(),
            'rate' => VatRules::enabled() ? VatRules::rate() : null,
            'registration' => VatRules::registrationNumber(),
            'inclusive' => VatRules::pricesIncludeVat(),
            'collected' => $collected,
            'refunded' => $refundedVat,
            'net' => round($collected - $refundedVat, 2),
            'by_month' => $byMonth,
        ];
    }

    /**
     * What went back, and why.
     *
     * The reason is the useful part. A month of "arrived damaged" is a courier
     * conversation; a month of "not as described" is a listing to rewrite.
     *
     * @return array{
     *     total:float, count:int, by_reason:array<int, array<string, mixed>>,
     *     by_method:array<int, array<string, mixed>>, lines:array<int, array<string, mixed>>
     * }
     */
    public static function refunds(string $from, string $to): array
    {
        $refunds = Refund::query()
            ->with('order:id,order_number')
            ->between($from, $to)
            ->latest('refunded_on')
            ->get();

        $group = fn (string $field, string $fallback) => $refunds
            ->groupBy(fn ($refund) => $refund->{$field} ?: $fallback)
            ->map(fn ($rows, $key) => [
                'label' => $key,
                'count' => $rows->count(),
                'amount' => round($rows->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        return [
            'total' => round($refunds->sum('amount'), 2),
            'count' => $refunds->count(),
            'by_reason' => $group('reason', 'Not given'),
            'by_method' => $group('method', 'Not recorded'),
            'lines' => $refunds->map(fn ($refund) => [
                'id' => $refund->id,
                'order_number' => $refund->order?->order_number ?? 'Removed order',
                'amount' => (float) $refund->amount,
                'reason' => $refund->reason,
                'method' => $refund->method,
                'on' => $refund->refunded_on
                    ? Carbon::parse($refund->refunded_on)->toDateString()
                    : $refund->created_at->toDateString(),
            ])->all(),
        ];
    }
}
