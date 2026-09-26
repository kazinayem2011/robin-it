import { describe, it, expect } from 'vitest';
import { orderableCeiling, preordersBeyondShelf } from '../orderable';
import { boundsFor } from '../cartBounds';

/**
 * How many can be ordered, pre-order included.
 *
 * The product page and the cart both capped at the shelf, which for a
 * pre-order product is nothing: a customer allowed three could take one.
 */
describe('orderableCeiling', () => {
    it('is the shelf for an ordinary product', () => {
        expect(orderableCeiling({ allow_preorder: false }, 4)).toBe(4);
        expect(orderableCeiling({ allow_preorder: false }, 0)).toBe(0);
    });

    it('goes beyond the shelf by the pre-order limit', () => {
        expect(
            orderableCeiling({ allow_preorder: true, preorder_limit: 3 }, 0),
        ).toBe(3);
        expect(
            orderableCeiling({ allow_preorder: true, preorder_limit: 3 }, 2),
        ).toBe(5);
    });

    /* Already owed units count against the limit, as on the server. */
    it('counts what is already owed', () => {
        expect(
            orderableCeiling({ allow_preorder: true, preorder_limit: 3 }, -2),
        ).toBe(1);
        expect(
            orderableCeiling({ allow_preorder: true, preorder_limit: 3 }, -5),
        ).toBe(0);
    });

    it('has no ceiling when no limit is set', () => {
        expect(
            orderableCeiling({ allow_preorder: true, preorder_limit: null }, 0),
        ).toBe(Number.POSITIVE_INFINITY);
    });
});

describe('the cart line bounds', () => {
    const line = (product, quantity = 1) => ({ quantity, product });

    it('lets a pre-order line go up to the limit', () => {
        const { max } = boundsFor(
            line({
                allow_preorder: true,
                preorder_limit: 3,
                stock_quantity: 0,
            }),
            { max_quantity_per_item: 20 },
        );

        expect(max).toBe(3);
    });

    it('still stops at the per-item cap when no limit is set', () => {
        const { max } = boundsFor(
            line({
                allow_preorder: true,
                preorder_limit: null,
                stock_quantity: 0,
            }),
            { max_quantity_per_item: 20 },
        );

        expect(max).toBe(20);
    });

    it('still stops at the shelf for an ordinary product', () => {
        const { max } = boundsFor(line({ stock_quantity: 4 }), {
            max_quantity_per_item: 20,
        });

        expect(max).toBe(4);
    });
});

describe('preordersBeyondShelf', () => {
    it('marks a line that asks for more than the shelf holds', () => {
        expect(preordersBeyondShelf({ allow_preorder: true }, 0, 1)).toBe(true);
        expect(preordersBeyondShelf({ allow_preorder: true }, 5, 2)).toBe(
            false,
        );
        expect(preordersBeyondShelf({ allow_preorder: false }, 0, 1)).toBe(
            false,
        );
    });
});
