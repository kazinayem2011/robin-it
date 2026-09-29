import { describe, it, expect } from 'vitest';
import { stockStatusFor } from '../stockStatus';

describe('stockStatusFor', () => {
    it('reads in stock, and reads as good news', () => {
        expect(
            stockStatusFor(
                { stock_status_label: 'In Stock', in_stock: true },
                { inStock: true },
            ),
        ).toEqual({ label: 'In Stock', tone: 'in' });
    });

    it('reads out of stock, and reads as bad news', () => {
        expect(
            stockStatusFor(
                { stock_status_label: 'Out of Stock', in_stock: false },
                { inStock: false },
            ),
        ).toEqual({ label: 'Out of Stock', tone: 'out' });
    });

    /**
     * The bug this exists for. A variant product's own label is computed from
     * the total across its options, so it says "In Stock" while the option in
     * front of the shopper is sold out.
     */
    it('does not call a sold-out option in stock', () => {
        const product = {
            has_variants: true,
            stock_status_label: 'In Stock',
            in_stock: true,
        };

        expect(
            stockStatusFor(product, {
                selectedVariant: { id: 1, in_stock: false },
                inStock: false,
            }),
        ).toEqual({ label: 'Sold Out', tone: 'out' });
    });

    it('reports the chosen option as in stock on its own figure', () => {
        const product = {
            has_variants: true,
            stock_status_label: 'In Stock',
            in_stock: true,
        };

        expect(
            stockStatusFor(product, {
                selectedVariant: { id: 2, in_stock: true },
                inStock: true,
            }),
        ).toEqual({ label: 'In Stock', tone: 'in' });
    });

    it('asks for a choice before reporting anything', () => {
        expect(
            stockStatusFor(
                { has_variants: true, stock_status_label: 'In Stock' },
                { selectedVariant: null, inStock: false },
            ),
        ).toEqual({ label: 'Choose an option', tone: 'unknown' });
    });

    it('prefers pre-order over out of stock when the shop allows it', () => {
        expect(
            stockStatusFor(
                { allow_preorder: true, stock_status_label: 'Pre-Order' },
                { inStock: false },
            ),
        ).toEqual({ label: 'Pre-Order', tone: 'waiting' });
    });

    /* "2-3 Days" is a promise about when, not a dead end. */
    it('does not paint the shop’s own wording as a dead end', () => {
        expect(
            stockStatusFor(
                { stock_status_label: '2-3 Days' },
                { inStock: false },
            ),
        ).toEqual({ label: '2-3 Days', tone: 'waiting' });
    });

    it('treats Unavailable as plainly out', () => {
        expect(
            stockStatusFor(
                { stock_status_label: 'Unavailable' },
                { inStock: false },
            ).tone,
        ).toBe('out');
    });

    it('survives being handed nothing', () => {
        expect(stockStatusFor(null)).toEqual({ label: '', tone: 'unknown' });
    });
});
