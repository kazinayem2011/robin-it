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

/**
 * Mirrors App\Models\Order::STATUSES.
 *
 * `confirmed` used to be listed here and has never existed on the backend —
 * picking it would have been rejected as an invalid status.
 */
export const ORDER_STATUS_OPTIONS = [
    { value: 'pending', label: 'Pending' },
    { value: 'processing', label: 'Processing' },
    { value: 'shipped', label: 'Shipped' },
    { value: 'delivered', label: 'Delivered' },
    { value: 'cancelled', label: 'Cancelled' },
];

/**
 * States an order cannot move out of — App\Models\Order::TERMINAL_STATUSES.
 *
 * A returned order has had its units accounted for line by line. A cancelled
 * one has handed its units back, and un-cancelling would rewrite a decision
 * the customer was already told about.
 */
export const TERMINAL_ORDER_STATUSES = ['cancelled', 'returned'];

/**
 * States an order can still be cancelled from — App\Models\Order::CANCELLABLE_FROM.
 *
 * Cancelling restores stock as though the goods never left, so it stops at the
 * point they do. After dispatch it is a return.
 */
export const CANCELLABLE_ORDER_STATUSES = ['pending', 'processing'];

/**
 * States a return can be raised from — App\Models\Order::RETURNABLE_FROM.
 *
 * A parcel coming back from the courier is goods returning, not an order that
 * never happened, so `shipped` counts as well as `delivered`.
 */
export const RETURNABLE_ORDER_STATUSES = ['shipped', 'delivered'];

/**
 * The statuses an order in `current` may actually be moved to.
 *
 * Offering one the backend will refuse just turns a dropdown into an error
 * toast, so the rules are mirrored here:
 *
 *   terminal              nothing; the select is replaced by a label
 *   shipped / delivered   no cancelling — the goods have left, so anything
 *                         coming back is a return, which records what
 *                         actually arrived and in what condition
 */
export const orderStatusOptionsFor = (current) => {
    if (TERMINAL_ORDER_STATUSES.includes(current)) return [];

    const canCancel = CANCELLABLE_ORDER_STATUSES.includes(current);

    return ORDER_STATUS_OPTIONS.filter(
        (option) => option.value !== 'cancelled' || canCancel,
    );
};
