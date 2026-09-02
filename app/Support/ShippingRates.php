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

    public const ZONE_INSIDE_DHAKA = 'inside_dhaka';

    public const ZONE_OUTSIDE_DHAKA = 'outside_dhaka';

    public const ZONES = [self::ZONE_INSIDE_DHAKA, self::ZONE_OUTSIDE_DHAKA];

    /**
     * The fee for one order.
     *
     * @param  string|null  $city  the delivery city, for orders and addresses
     *                             saved before the zone was asked for; the cart
     *                             page has no address at all and is quoted the
     *                             inside-Dhaka rate
     * @param  float  $subtotal  goods total, before any coupon — a promo code
     *                           should not cost the customer their free
     *                           delivery
     * @param  string|null  $zone  what the customer chose, which beats anything
     *                             inferred from prose
     */
    public static function feeFor(?string $city, float $subtotal, ?string $zone = null): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        $threshold = self::freeThreshold();

        if ($threshold !== null && $subtotal >= $threshold) {
            return 0.0;
        }

        /*
         * A stated zone settles it. Reading the city is a guess that was only
         * ever reliable while the city had a field of its own, and is now the
         * fallback for orders and addresses recorded before this was asked.
         */
        if (in_array($zone, self::ZONES, true)) {
            return $zone === self::ZONE_INSIDE_DHAKA
                ? self::insideDhaka()
                : self::outsideDhaka();
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

    /**
     * The rate for each zone, for a page that wants to show both before the
     * customer has chosen.
     *
     * @return array<string, float>
     */
    public static function byZone(): array
    {
        return [
            self::ZONE_INSIDE_DHAKA => self::insideDhaka(),
            self::ZONE_OUTSIDE_DHAKA => self::outsideDhaka(),
        ];
    }

    /** Whatever was given, if it is a zone this shop knows; otherwise null. */
    public static function normaliseZone(?string $zone): ?string
    {
        return in_array($zone, self::ZONES, true) ? $zone : null;
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
