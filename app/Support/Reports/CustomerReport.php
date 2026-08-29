<?php

namespace App\Support\Reports;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who buys, how often, and who is worth writing to.
 *
 * Feeds the campaign screen more than anything else: a shop with a thousand
 * customers and a text budget should be writing to the two hundred who come
 * back, not to everyone who ever bought a cable.
 *
 * Guest orders are counted by the phone number on them. A customer who never
 * made an account but has ordered five times is a repeat customer, and treating
 * them as five strangers is how a shop concludes nobody comes back.
 */
class CustomerReport
{
    private const NOT_A_SALE = ['cancelled', 'returned'];

    /**
     * @return array{
     *     totals:array<string, mixed>,
     *     top:array<int, array<string, mixed>>,
     *     new_vs_returning:array{new:int, returning:int}
     * }
     */
    public static function for(string $from, string $to, int $limit = 50): array
    {
        $orders = Order::query()
            ->with('user:id,name,email,phone')
            ->whereNotIn('status', self::NOT_A_SALE)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get();

        // One key per person, whether or not they ever made an account.
        $people = $orders->groupBy(fn (Order $order) => self::keyFor($order));

        $top = $people->map(function ($theirs, $key) {
            $first = $theirs->first();

            return [
                'key' => $key,
                'name' => $first->user?->name ?? $first->recipient_name,
                'email' => $first->user?->email,
                'phone' => $first->notifiablePhone(),
                'has_account' => $first->user_id !== null,
                'orders' => $theirs->count(),
                'spent' => round($theirs->sum(fn ($o) => (float) $o->total), 2),
                'average' => round($theirs->sum(fn ($o) => (float) $o->total) / $theirs->count(), 2),
                'last_order' => $theirs->max('created_at')?->toDateString(),
            ];
        })
            ->sortByDesc('spent')
            ->take($limit)
            ->values();

        /*
         * New means the first order this shop has from them fell inside the
         * period — checked against the whole history, not against the period,
         * or every customer looks new whenever the range is short.
         */
        $new = 0;

        foreach ($people as $key => $theirs) {
            $firstEver = self::firstOrderDate($theirs->first());

            if ($firstEver && $firstEver >= $from) {
                $new++;
            }
        }

        $spend = $orders->sum(fn ($o) => (float) $o->total);

        return [
            'totals' => [
                'customers' => $people->count(),
                'orders' => $orders->count(),
                'revenue' => round($spend, 2),
                'average_per_customer' => $people->count() > 0
                    ? round($spend / $people->count(), 2)
                    : 0.0,
                'orders_per_customer' => $people->count() > 0
                    ? round($orders->count() / $people->count(), 2)
                    : 0.0,
                'with_account' => $people->filter(fn ($t) => $t->first()->user_id !== null)->count(),
                'registered_total' => User::where('role', User::ROLE_CUSTOMER)->count(),
            ],
            'top' => $top->all(),
            'new_vs_returning' => [
                'new' => $new,
                'returning' => max(0, $people->count() - $new),
            ],
        ];
    }

    /**
     * One person, however they ordered.
     *
     * The account where there is one, and the phone number otherwise — which is
     * what a guest checkout always has, and what the courier rings.
     */
    private static function keyFor(Order $order): string
    {
        if ($order->user_id) {
            return 'user:'.$order->user_id;
        }

        return 'phone:'.($order->notifiablePhone() ?? 'unknown:'.$order->id);
    }

    /** When this shop first heard from them, ever. */
    private static function firstOrderDate(Order $order): ?string
    {
        $query = Order::query()->whereNotIn('status', self::NOT_A_SALE);

        if ($order->user_id) {
            $query->where('user_id', $order->user_id);
        } else {
            $phone = $order->notifiablePhone();

            if (! $phone) {
                return null;
            }

            /*
             * The builder's JSON syntax, not raw SQL. JSON_UNQUOTE is MySQL's
             * spelling and the test database is SQLite, so a hand-written
             * function name works in production and fails everywhere else —
             * which is the worst way round to find out.
             */
            $query->where('shipping_address->phone', $phone);
        }

        $first = $query->min('created_at');

        return $first ? substr((string) $first, 0, 10) : null;
    }

    /**
     * Customers who have not ordered in a while.
     *
     * The list a shop should be writing to. Somebody who bought three times and
     * then stopped six months ago is worth a message far more than a stranger
     * on the mailing list.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lapsed(int $afterDays = 120, int $limit = 100): array
    {
        return DB::table('orders')
            ->whereNotIn('status', self::NOT_A_SALE)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('MAX(created_at) < ?', [now()->subDays($afterDays)])
            ->select('user_id')
            ->selectRaw('COUNT(*) as orders, SUM(total) as spent, MAX(created_at) as last_order')
            ->orderByDesc('spent')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $user = User::find($row->user_id, ['id', 'name', 'email', 'phone', 'accepts_marketing']);

                return [
                    'user_id' => $row->user_id,
                    'name' => $user?->name,
                    'email' => $user?->email,
                    'phone' => $user?->phone,
                    'orders' => (int) $row->orders,
                    'spent' => round((float) $row->spent, 2),
                    'last_order' => substr((string) $row->last_order, 0, 10),
                    'days_since' => (int) now()->diffInDays($row->last_order),
                    // Whether writing to them is even allowed.
                    'reachable' => (bool) ($user?->accepts_marketing),
                ];
            })
            ->all();
    }
}
