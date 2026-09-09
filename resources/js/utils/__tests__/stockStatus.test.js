import { describe, it, expect } from 'vitest';
import { stockStatusFor } from '../stockStatus';

describe('stockStatusFor', () => {
    it('reads in stock, and reads as good news', () => {
        expect(
            stockStatusFor(
                { stock_status_label: 'In Stock', stock_quantity: 4 },
                { availableStock: 4 },
            ),
        ).toEqual({ label: 'In Stock', tone: 'in' });
    });

    it('reads out of stock, and reads as bad news', () => {
        expect(
            stockStatusFor(
                { stock_status_label: 'Out of Stock', stock_quantity: 0 },
                { availableStock: 0 },
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
            stock_quantity: 5,
        };

        expect(
            stockStatusFor(product, {
                selectedVariant: { id: 1, stock_quantity: 0 },
                availableStock: 0,
            }),
        ).toEqual({ label: 'Out of Stock', tone: 'out' });
    });

    it('reports the chosen option as in stock on its own figure', () => {
        const product = {
            has_variants: true,
            stock_status_label: 'In Stock',
            stock_quantity: 5,
        };

        expect(
            stockStatusFor(product, {
                selectedVariant: { id: 2, stock_quantity: 3 },
                availableStock: 3,
            }),
        ).toEqual({ label: 'In Stock', tone: 'in' });
    });

    it('asks for a choice before reporting anything', () => {
        expect(
            stockStatusFor(
                { has_variants: true, stock_status_label: 'In Stock' },
                { selectedVariant: null, availableStock: 0 },
            ),
        ).toEqual({ label: 'Choose an option', tone: 'unknown' });
    });

    it('prefers pre-order over out of stock when the shop allows it', () => {
        expect(
            stockStatusFor(
                { allow_preorder: true, stock_status_label: 'Pre-Order' },
                { availableStock: 0 },
            ),
        ).toEqual({ label: 'Pre-Order', tone: 'waiting' });
    });

    /* "2-3 Days" is a promise about when, not a dead end. */
    it('does not paint the shop’s own wording as a dead end', () => {
        expect(
            stockStatusFor(
                { stock_status_label: '2-3 Days' },
                { availableStock: 0 },
            ),
        ).toEqual({ label: '2-3 Days', tone: 'waiting' });
    });

    it('treats Unavailable as plainly out', () => {
        expect(
            stockStatusFor(
                { stock_status_label: 'Unavailable' },
                { availableStock: 0 },
            ).tone,
        ).toBe('out');
    });

    it('survives being handed nothing', () => {
        expect(stockStatusFor(null)).toEqual({ label: '', tone: 'unknown' });
    });
});
