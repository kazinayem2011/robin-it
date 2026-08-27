<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class PhoneHelper
{
    /**
     * What a validator should accept, once the number has been normalised.
     *
     * Seven places had their own copy of this regex and every one of them ran
     * against whatever the customer typed, before normalisation — so the app
     * rejected the very format it prints. An order confirmation shows
     * "+880 1711-000000"; pasting that back into order tracking failed on the
     * space and the hyphen, though everything downstream would have coped.
     *
     * Normalise first, then match this.
     */
    public const RULE = 'regex:/^01[3-9]\d{8}$/';

    public const MESSAGE = 'Please enter the full 11-digit mobile number used when placing the order.';

    /**
     * Normalize Bangladeshi mobile number to standard 11-digit format (e.g. 01711223344).
     * Strips whitespace, hyphens, parentheses, and '+88' / '88' prefixes.
     */
    public static function normalizeBdPhone(?string $input): ?string
    {
        if (empty($input)) {
            return null;
        }

        // Remove all non-digit characters except leading plus
        $cleaned = preg_replace('/[^0-9+]/', '', trim($input));

        if (str_starts_with($cleaned, '+88')) {
            $cleaned = substr($cleaned, 3);
        } elseif (str_starts_with($cleaned, '88') && strlen($cleaned) === 13) {
            $cleaned = substr($cleaned, 2);
        }

        /*
         * The leading zero, put back.
         *
         * Numbers are displayed as "+880 1711-000000", which reads as a
         * country code and then a number, so people copy the half after the
         * space and lose the 0. Ten digits starting 1[3-9] can only be a
         * mobile missing its zero — there is nothing else it could be.
         */
        if (strlen($cleaned) === 10 && preg_match('/^1[3-9]/', $cleaned)) {
            $cleaned = '0'.$cleaned;
        }

        return $cleaned;
    }

    /**
     * Check if a given string matches the standard Bangladeshi mobile number pattern (013-019).
     */
    public static function isValidBdPhone(?string $input): bool
    {
        $normalized = self::normalizeBdPhone($input);
        if (! $normalized) {
            return false;
        }

        return (bool) preg_match('/^01[3-9]\d{8}$/', $normalized);
    }

    /**
     * Put the canonical number back on the request, before validation runs.
     *
     * So the rules judge the number rather than its punctuation, and whatever
     * is stored afterwards is the same eleven digits however it was typed.
     */
    public static function canonicalise(Request $request, string ...$keys): void
    {
        foreach ($keys as $key) {
            $raw = $request->input($key);

            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            // A number too mangled to normalise is left as it was, so the
            // validator can say so rather than this quietly rewriting it.
            $request->merge([$key => self::normalizeBdPhone($raw) ?? $raw]);
        }
    }

    /**
     * Format a Bangladeshi mobile number for readable UI display (e.g. +880 1711-223344).
     */
    public static function formatDisplay(?string $input): string
    {
        $normalized = self::normalizeBdPhone($input);
        if (! $normalized || strlen($normalized) !== 11) {
            return $input ?? '—';
        }

        return '+880 '.substr($normalized, 1, 4).'-'.substr($normalized, 5);
    }
}
