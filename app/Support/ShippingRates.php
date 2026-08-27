<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * What delivery costs, as the admin set it.
 *
 * The Settings screen has collected "Delivery Inside Dhaka", "Delivery Outside
 * Dhaka" and a free-shipping threshold for a long while, and nothing ever read
 * any of them: CartService carried a hardcoded `const SHIPPING_FEE = 60.0` and
 * charged that on every order. Saving a rate changed nothing, silently — the
 * same shape of problem as the SMTP settings nobody read.
 */
class ShippingRates
{
    /** Used when the shop has not set a rate, matching the previous constant. */
    public const DEFAULT_FEE = 60.0;

    /**
     * The fee for one order.
     *
     * @param  string|null  $city  the delivery city, when it is known; the cart
     *                             page has no address yet and is quoted the
     *                             inside-Dhaka rate
     * @param  float  $subtotal  goods total, before any coupon — a promo code
     *                           should not cost the customer their free
     *                           delivery
     */
    public static function feeFor(?string $city, float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        $threshold = self::freeThreshold();

        if ($threshold !== null && $subtotal >= $threshold) {
            return 0.0;
        }

        /*
         * A city we have not been told is not the same as a city outside
         * Dhaka. The cart page has no address yet, and quoting the higher
         * rate there would overstate the total for the majority of orders —
         * so an unknown destination is quoted the local rate, and checkout
         * charges what the address actually says.
         */
        if (blank($city)) {
            return self::insideDhaka();
        }

        return self::isInsideDhaka($city) ? self::insideDhaka() : self::outsideDhaka();
    }

    public static function insideDhaka(): float
    {
        return self::amount('shipping_inside_dhaka', self::DEFAULT_FEE);
    }

    public static function outsideDhaka(): float
    {
        return self::amount('shipping_outside_dhaka', self::insideDhaka());
    }

    /**
     * Spend at or above which delivery is free, or null when the shop does not
     * offer it. Zero and blank both mean "no free delivery" rather than
     * "everything ships free", which is the reading that costs money.
     */
    public static function freeThreshold(): ?float
    {
        $raw = SiteSetting::get('free_shipping_threshold');

        if (blank($raw) || ! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return $value > 0 ? $value : null;
    }

    /**
     * Whether an address is on the inside-Dhaka rate.
     *
     * Matched on the city the customer typed. Deliberately generous — "Dhaka",
     * "dhaka-1205", "Uttara, Dhaka" all count — because the alternative is
     * overcharging someone for a local delivery on a spelling.
     */
    public static function isInsideDhaka(?string $city): bool
    {
        return str_contains(mb_strtolower(trim((string) $city)), 'dhaka');
    }

    private static function amount(string $key, float $default): float
    {
        $raw = SiteSetting::get($key);

        if (blank($raw) || ! is_numeric($raw)) {
            return $default;
        }

        return max(0.0, (float) $raw);
    }
}
