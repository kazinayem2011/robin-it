import { describe, it, expect } from 'vitest';
import { isPreorderLine, lineInStock, orderableCeiling } from '../orderable';
import { boundsFor } from '../cartBounds';

/**
 * Whether something can be ordered, from yes/no answers only.
 *
 * The storefront is never sent a stock figure, so these cannot — and must
 * not — depend on one: the server refuses what it cannot supply.
 */
describe('orderableCeiling', () => {
    it('has no ceiling for an ordinary product in stock', () => {
        expect(orderableCeiling({ allow_preorder: false }, true)).toBe(
            Number.POSITIVE_INFINITY,
        );
    });

    it('is nothing for an ordinary product that is sold out', () => {
        expect(orderableCeiling({ allow_preorder: false }, false)).toBe(0);
    });

    it('has no ceiling for a pre-order product, in stock or not', () => {
        expect(orderableCeiling({ allow_preorder: true }, false)).toBe(
            Number.POSITIVE_INFINITY,
        );
        expect(orderableCeiling({ allow_preorder: true }, true)).toBe(
            Number.POSITIVE_INFINITY,
        );
    });

    it('is nothing when there is no product', () => {
        expect(orderableCeiling(null, false)).toBe(0);
    });
});

describe('the cart line bounds', () => {
    const line = (product, quantity = 1) => ({ quantity, product });

    it('lets a pre-order line go to the per-item cap', () => {
        const { max } = boundsFor(
            line({ allow_preorder: true, in_stock: false }),
            { max_quantity_per_item: 20 },
        );

        expect(max).toBe(20);
    });

    it('lets an ordinary product in stock go to the per-item cap', () => {
        const { max } = boundsFor(line({ in_stock: true }), {
            max_quantity_per_item: 20,
        });

        expect(max).toBe(20);
    });
});

describe('isPreorderLine', () => {
    it('marks a pre-order product whose shelf is empty', () => {
        expect(isPreorderLine({ allow_preorder: true }, false)).toBe(true);
    });

    it('does not mark one on the shelf, or an ordinary product', () => {
        expect(isPreorderLine({ allow_preorder: true }, true)).toBe(false);
        expect(isPreorderLine({ allow_preorder: false }, false)).toBe(false);
    });
});

describe('lineInStock', () => {
    it('reads the option when the line has one', () => {
        expect(
            lineInStock({
                product: { in_stock: true },
                variant: { in_stock: false },
            }),
        ).toBe(false);
    });

    it('reads the product otherwise, in either spelling', () => {
        expect(lineInStock({ product: { in_stock: true } })).toBe(true);
        expect(lineInStock({ product: { inStock: true } })).toBe(true);
        expect(lineInStock({ product: {} })).toBe(false);
    });

    it('treats an option that has gone as not in stock', () => {
        expect(
            lineInStock({
                product_variant_id: 3,
                variant: null,
                product: { in_stock: true },
            }),
        ).toBe(false);
    });
});
