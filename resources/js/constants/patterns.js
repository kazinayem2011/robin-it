/**
 * Centralized regex patterns — Single Source of Truth for all validation.
 *
 * BD_PHONE_REGEX: Accepts +880, 880, 01, or 1 prefix followed by
 * an operator digit (3–9) and 8 more digits (total 11 digits local).
 */
export const BD_PHONE_REGEX = /^(?:\+8801|8801|01|1)[3-9]\d{8}$/;

/**
 * Quick test helper — strips spaces and dashes before testing.
 */
export const isBDPhone = (value = '') =>
    BD_PHONE_REGEX.test(value.trim().replace(/[\s-]/g, ''));
