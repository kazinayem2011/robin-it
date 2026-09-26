import { orderableCeiling } from './orderable';

/**
 * How many of one line a customer may have.
 *
 * The same three rules CartService::updateItemQuantity() enforces server-side:
 * never more than is in stock, never more than the per-item cap, never below
 * the product's minimum order quantity. Kept in one place because the cart and
 * the checkout summary both offer the buttons, and two copies of a rule about
 * money drift apart.
 *
 * @param item  a cart line, with its product and option loaded
 * @param cart  the cart it came from, which carries the shop's per-item cap
 */
export const boundsFor = (item, cart) => {
    if (!item) return { min: 1, max: Number.POSITIVE_INFINITY };

    const stock =
        item.variant?.stock_quantity ?? item.product?.stock_quantity ?? null;

    /* Falls back to the server's own constant. Sent with the cart rather than
       written here, so the two cannot disagree after somebody changes it. */
    const cap = cart?.max_quantity_per_item ?? 20;

    /* Not the shelf alone: a pre-order product may go beyond it by its
       limit. Capping at the shelf held a pre-order line at nothing. */
    return {
        min: Math.max(1, Number(item.product?.min_order_quantity) || 1),
        max:
            stock === null
                ? cap
                : Math.min(orderableCeiling(item.product, stock), cap),
    };
};

export default boundsFor;
