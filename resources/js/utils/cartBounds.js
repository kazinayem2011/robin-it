import { lineInStock, orderableCeiling } from './orderable';

/**
 * How many of one line a customer may have.
 *
 * The per-item cap and the product's minimum order quantity, as
 * CartService::updateItemQuantity() enforces them. Stock is not a bound here:
 * the storefront is never told how much there is, so an orderable line goes
 * up to the cap and the server refuses anything it cannot supply. Kept in one
 * place because the cart and the checkout summary both offer the buttons, and
 * two copies of a rule about money drift apart.
 *
 * @param item  a cart line, with its product and option loaded
 * @param cart  the cart it came from, which carries the shop's per-item cap
 */
/*
 * The most of one item a single order takes — CartService::MAX_QUANTITY_PER_ITEM
 * on the server, which refuses more ("Contact us for bulk orders"). The cart
 * sends its own figure; this is the same number for screens that have no cart.
 */
export const MAX_PER_ITEM = 20;

export const boundsFor = (item, cart) => {
    if (!item) return { min: 1, max: Number.POSITIVE_INFINITY };

    /* Falls back to the server's own constant. Sent with the cart rather than
       written here, so the two cannot disagree after somebody changes it. */
    const cap = cart?.max_quantity_per_item ?? MAX_PER_ITEM;

    return {
        min: Math.max(1, Number(item.product?.min_order_quantity) || 1),
        max: Math.min(orderableCeiling(item.product, lineInStock(item)), cap),
    };
};

export default boundsFor;
