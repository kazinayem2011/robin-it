/**
 * Centralized Admin Constants and Options (DRY & SSOT).
 */
export const NAVBAR_BADGE_OPTIONS = [
    { value: '', label: 'No Badge' },
    { value: 'HOT', label: 'HOT (Orange)' },
    { value: 'POPULAR', label: 'POPULAR (Blue)' },
    { value: 'NEW', label: 'NEW (Green)' },
    { value: 'SALE', label: 'SALE (Red)' },
];

export const ORDER_STATUS_OPTIONS = [
    { value: 'pending', label: 'Pending' },
    { value: 'confirmed', label: 'Confirmed' },
    { value: 'processing', label: 'Processing' },
    { value: 'shipped', label: 'Shipped' },
    { value: 'delivered', label: 'Delivered' },
    { value: 'cancelled', label: 'Cancelled' },
];
