<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The calendar date where the shop is.
 *
 * The app keeps its clock in UTC, six hours behind Bangladesh. Between
 * midnight and six in the morning a shop's "today" is the server's tomorrow,
 * so a delivery booked at 1am — dated today by the screen — was refused as
 * "dated in the future", and a payment recorded then was dated yesterday.
 *
 * Only calendar dates that people type or read go through this. Timestamps
 * stay in UTC.
 */
class ShopDate
{
    public static function timezone(): string
    {
        return (string) config('app.shop_timezone', 'Asia/Dhaka');
    }

    /** Today at the shop, as Y-m-d. */
    public static function today(): string
    {
        return now(self::timezone())->toDateString();
    }

    /**
     * A stored moment, written as the shop's clock reads it.
     *
     * Timestamps are kept in UTC; formatted as they were, a delivery booked at
     * 12:40 in the morning in Dhaka read "6:40 PM" the day before.
     */
    public static function show(?CarbonInterface $at, string $format = 'd M Y, g:i A'): ?string
    {
        return $at?->copy()->timezone(self::timezone())->format($format);
    }

    /**
     * A day at the shop as the UTC moments it runs between, for a filter over
     * stored timestamps: [start, end].
     *
     * @return array{0: string, 1: string}
     */
    public static function dayBounds(string $from, string $to): array
    {
        return [
            Carbon::parse($from, self::timezone())->startOfDay()->utc()->toDateTimeString(),
            Carbon::parse($to, self::timezone())->endOfDay()->utc()->toDateTimeString(),
        ];
    }

    /** A validation rule: the date is not after today at the shop. */
    public static function notInFuture(): string
    {
        return 'before_or_equal:'.self::today();
    }
}
