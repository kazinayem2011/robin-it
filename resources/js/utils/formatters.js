/**
 * FORMATTERS UTILITY (DRY & SSOT)
 * Centralized helpers for currency, date, phone, and discounts.
 */

/**
 * Format numeric value to Bangladeshi Taka currency format (e.g. ৳72,500).
 */
export const formatBdt = (amount) => {
    if (amount === undefined || amount === null || amount === '') return '৳0';
    const num =
        typeof amount === 'number'
            ? amount
            : parseFloat(String(amount).replace(/[^0-9.-]+/g, ''));
    if (isNaN(num)) return '৳0';
    return `৳${Math.round(num).toLocaleString('en-IN')}`;
};

/**
 * Format date string to readable format (e.g. "18 Aug 2026").
 */
export const formatDate = (dateStr, options = {}) => {
    if (!dateStr) return '—';
    try {
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return String(dateStr);
        return date.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            ...options,
        });
    } catch {
        return String(dateStr);
    }
};

/**
 * Format a Bangladeshi phone number for readable display (e.g. +880 1711-223344).
 */
export const formatBdPhone = (phone) => {
    if (!phone) return '—';
    const cleaned = String(phone).replace(/[^0-9]/g, '');
    if (cleaned.length === 11 && cleaned.startsWith('01')) {
        return `+880 ${cleaned.substring(1, 5)}-${cleaned.substring(5)}`;
    }
    if (cleaned.length === 13 && cleaned.startsWith('8801')) {
        return `+880 ${cleaned.substring(3, 7)}-${cleaned.substring(7)}`;
    }
    return phone;
};

/**
 * Calculate percentage discount between regular price and discount price.
 */
export const calculateDiscount = (regularPrice, discountPrice) => {
    const reg = parseFloat(regularPrice);
    const disc = parseFloat(discountPrice);
    if (!reg || !disc || disc >= reg) return null;
    const pct = Math.round(((reg - disc) / reg) * 100);
    const saving = reg - disc;
    return {
        percent: `-${pct}%`,
        percentNum: pct,
        saving: formatBdt(saving),
        savingNum: saving,
    };
};
