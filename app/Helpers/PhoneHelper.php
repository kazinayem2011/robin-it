<?php

namespace App\Helpers;

class PhoneHelper
{
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
