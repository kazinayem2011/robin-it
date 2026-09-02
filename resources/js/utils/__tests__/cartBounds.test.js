import { describe, it, expect } from 'vitest';
import { boundsFor } from '../cartBounds';

/**
 * How many of one line a customer may have.
 *
 * The cart page and the checkout summary both draw "+" and "−" from this, and
 * the server enforces the same three rules in
 * CartService::updateItemQuantity(). A disagreement here is a button that asks
 * for a quantity the next request refuses — which, now the number updates on
 * the spot, shows as it going up and snapping back.
 */
describe('boundsFor', () => {
    const line = (product = {}, variant = null, cart = {}) => ({
        id: 1,
        quantity: 1,
        product: { stock_quantity: 10, min_order_quantity: 1, ...product },
        variant,
    });

    const cartWith = (cap) => ({ max_quantity_per_item: cap });

    it('stops at the stock on the line', () => {
        expect(boundsFor(line({ stock_quantity: 3 }), cartWith(20)).max).toBe(3);
    });

    it('stops at the per-item cap when stock is deeper than it', () => {
        expect(
            boundsFor(line({ stock_quantity: 500 }), cartWith(20)).max,
        ).toBe(20);
    });

    /* Stock and price live on the option for a variant product, so the
       option's shelf is the one that counts. */
    it('prefers the option’s stock over the product’s', () => {
        const item = line({ stock_quantity: 99 }, { stock_quantity: 2 });

        expect(boundsFor(item, cartWith(20)).max).toBe(2);
    });

    it('honours a minimum order quantity', () => {
        expect(
            boundsFor(line({ min_order_quantity: 3 }), cartWith(20)).min,
        ).toBe(3);
    });

    it('treats a missing minimum as one', () => {
        expect(
            boundsFor(line({ min_order_quantity: null }), cartWith(20)).min,
        ).toBe(1);
    });

    /*
     * The cap is sent with the cart rather than written into the page. If it
     * ever fails to arrive the fallback has to match the server's constant,
     * which is 20 — a larger guess would offer quantities that get refused.
     */
    it('falls back to the server’s cap of 20 when the cart does not say', () => {
        expect(boundsFor(line({ stock_quantity: 500 }), {}).max).toBe(20);
        expect(boundsFor(line({ stock_quantity: 500 }), null).max).toBe(20);
    });

    /* An unknown stock is not the same as no stock: some lines arrive without
       the column, and refusing to let them move at all would be worse. */
    it('allows up to the cap when stock is unknown', () => {
        const item = line({ stock_quantity: null });

        expect(boundsFor(item, cartWith(20)).max).toBe(20);
    });

    it('never returns a max below the min it also returns', () => {
        const item = line({ stock_quantity: 0, min_order_quantity: 1 });
        const { min, max } = boundsFor(item, cartWith(20));

        // Out of stock: min 1, max 0. The page must not offer "+" — and it
        // does not, because quantity >= max on the first unit.
        expect(min).toBe(1);
        expect(max).toBe(0);
    });

    it('is safe with no item at all', () => {
        expect(boundsFor(null, cartWith(20))).toEqual({
            min: 1,
            max: Number.POSITIVE_INFINITY,
        });
    });
});
