<?php

namespace App\Support;

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

    /** A validation rule: the date is not after today at the shop. */
    public static function notInFuture(): string
    {
        return 'before_or_equal:'.self::today();
    }
}
