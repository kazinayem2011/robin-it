<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * How the shop charges VAT, as the admin set it.
 *
 * Nothing here is guessed. Whether VAT applies at all, at what rate, and —
 * the one that changes the arithmetic rather than just the paperwork —
 * whether the prices on the shelf already include it, are all settings.
 *
 * Inclusive is the usual arrangement in Bangladeshi retail: the price on the
 * label is what the customer hands over, and the VAT is the portion of it the
 * shop owes. Exclusive adds it on at checkout. Reading one as the other
 * misstates both the customer's total and the shop's revenue, so it is asked
 * rather than assumed, and the answer is frozen onto every order.
 *
 * VAT is charged on goods, after any discount, and not on delivery. Delivery
 * is collected on the courier's behalf; if that ever needs to change it should
 * be a deliberate decision with an accountant, not a default nobody chose.
 */
class VatRules
{
    /** Off unless the shop turns it on: no figure moves until someone decides. */
    public static function enabled(): bool
    {
        return SiteSetting::get('vat_enabled') === '1';
    }

    /** Percentage, e.g. 15.0. */
    public static function rate(): float
    {
        $raw = SiteSetting::get('vat_rate');

        if (blank($raw) || ! is_numeric($raw)) {
            return 0.0;
        }

        return max(0.0, min(100.0, (float) $raw));
    }

    /** Whether the prices customers see already contain the VAT. */
    public static function pricesIncludeVat(): bool
    {
        return SiteSetting::get('vat_inclusive', '1') !== '0';
    }

    /** The registration number that has to appear on a VAT invoice. */
    public static function registrationNumber(): ?string
    {
        $bin = trim((string) SiteSetting::get('vat_number', ''));

        return $bin === '' ? null : $bin;
    }

    /**
     * The VAT on a sale of `$goods` (already net of any discount).
     *
     * Inclusive: the goods figure contains the tax, so it is extracted —
     * 1,150 at 15% holds 150, not 172.50.
     *
     * Exclusive: the tax sits on top and is added.
     */
    public static function on(float $goods): float
    {
        if (! self::enabled() || $goods <= 0) {
            return 0.0;
        }

        $rate = self::rate();

        if ($rate <= 0) {
            return 0.0;
        }

        return self::pricesIncludeVat()
            ? round($goods - ($goods / (1 + $rate / 100)), 2)
            : round($goods * $rate / 100, 2);
    }

    /**
     * What the customer pays for `$goods` once VAT is settled.
     *
     * Inclusive prices are already the answer; exclusive ones grow.
     */
    public static function grossFor(float $goods): float
    {
        if (! self::enabled() || self::pricesIncludeVat()) {
            return round($goods, 2);
        }

        return round($goods + self::on($goods), 2);
    }

    /**
     * The part of a sale the shop actually earned — what is left once the VAT
     * held for the government is taken out.
     *
     * With exclusive pricing the goods figure never contained the tax, so it
     * is already the answer.
     */
    public static function netOf(float $goods, float $vat): float
    {
        return self::pricesIncludeVat()
            ? round($goods - $vat, 2)
            : round($goods, 2);
    }
}
