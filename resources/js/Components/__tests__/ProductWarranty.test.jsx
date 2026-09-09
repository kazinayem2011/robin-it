import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import ProductWarranty from '../ProductWarranty';

/**
 * The warranty a shop records, finally shown to the customer.
 *
 * Both fields were captured by the admin form and read by no storefront page,
 * email or invoice — somebody could write four clauses of policy and no
 * shopper would see one.
 */
describe('ProductWarranty', () => {
    it('states the period the claim is counted from', () => {
        render(<ProductWarranty months={24} />);

        expect(screen.getByText('24 months')).toBeTruthy();
        expect(screen.getByText(/from the date of purchase/)).toBeTruthy();
    });

    it('says month, not months, for one', () => {
        render(<ProductWarranty months={1} />);

        expect(screen.getByText('1 month')).toBeTruthy();
    });

    /* Typed a clause per line, so read back as a list. */
    it('reads several lines back as separate clauses', () => {
        render(
            <ProductWarranty
                months={24}
                terms={
                    '2 Years warranty on the unit\n' +
                    'Battery and adapter: 1 year\n' +
                    'Physical damage is not covered'
                }
            />,
        );

        const items = screen.getAllByRole('listitem');
        expect(items).toHaveLength(3);
        expect(items[1].textContent).toBe('Battery and adapter: 1 year');
    });

    /* A double return between clauses is how people type a list. */
    it('drops blank lines rather than making empty bullets', () => {
        render(<ProductWarranty terms={'Covered\n\n   \nNot covered'} />);

        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });

    it('does not make a list out of a single clause', () => {
        render(<ProductWarranty terms="2 Years warranty on the unit" />);

        expect(screen.queryAllByRole('listitem')).toHaveLength(0);
        expect(screen.getByText('2 Years warranty on the unit')).toBeTruthy();
    });

    /**
     * Says what is true. "Standard warranty applies" sends a shopper looking
     * for terms nobody wrote.
     */
    it('says nothing is recorded when nothing is', () => {
        render(<ProductWarranty />);

        expect(screen.getByText(/No warranty has been recorded/)).toBeTruthy();
    });

    /* A period on its own is a warranty, so it does not claim otherwise. */
    it('does not claim nothing is recorded when a period is', () => {
        render(<ProductWarranty months={12} />);

        expect(screen.queryByText(/No warranty has been recorded/)).toBeNull();
    });
});
