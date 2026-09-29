import { describe, it, expect } from 'vitest';
import { boundsFor } from '../cartBounds';

/**
 * How many of one line a customer may have.
 *
 * The cart page and the checkout summary both draw "+" and "−" from this, and
 * the server enforces the same rules in CartService::updateItemQuantity(). The
 * storefront is never told how many are in stock, so stock only decides
 * whether the line can be ordered at all; the server refuses the rest.
 */
describe('boundsFor', () => {
    const line = (product = {}, variant = null) => ({
        id: 1,
        quantity: 1,
        product: { in_stock: true, min_order_quantity: 1, ...product },
        variant,
    });

    const cartWith = (cap) => ({ max_quantity_per_item: cap });

    it('goes to the per-item cap when the line is in stock', () => {
        expect(boundsFor(line(), cartWith(20)).max).toBe(20);
    });

    it('stops at nothing when it is sold out and not on pre-order', () => {
        expect(boundsFor(line({ in_stock: false }), cartWith(20)).max).toBe(0);
    });

    it('goes to the cap on a pre-order product with an empty shelf', () => {
        expect(
            boundsFor(
                line({ in_stock: false, allow_preorder: true }),
                cartWith(20),
            ).max,
        ).toBe(20);
    });

    /* The option is what is being bought, so its answer is the one that counts. */
    it('prefers the option’s answer over the product’s', () => {
        const item = line({ in_stock: true }, { in_stock: false });

        expect(boundsFor(item, cartWith(20)).max).toBe(0);
    });

    it('does not depend on any stock figure', () => {
        const item = line({ in_stock: true, stock_quantity: 2 });

        expect(boundsFor(item, cartWith(20)).max).toBe(20);
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
        expect(boundsFor(line(), {}).max).toBe(20);
        expect(boundsFor(line(), null).max).toBe(20);
    });

    it('returns min 1, max 0 for a sold-out line', () => {
        const { min, max } = boundsFor(
            line({ in_stock: false, min_order_quantity: 1 }),
            cartWith(20),
        );

        // The page must not offer "+" — and it does not, because
        // quantity >= max on the first unit.
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
