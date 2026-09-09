import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import ProductSpecifications from '../ProductSpecifications';

/**
 * The identifiers a customer cross-checks a machine by.
 *
 * The product form captured a model and a part number and displayed neither.
 * Both were reaching the page's structured data — where a search engine reads
 * them — while the page itself showed nothing, so a shopper comparing a listing
 * against a manufacturer's own page had nothing to compare.
 */
describe('ProductSpecifications', () => {
    const SPECS = [{ id: 1, group: 'Display', name: 'Size', value: '15.6"' }];

    it('leads with the model and the part number', () => {
        render(
            <ProductSpecifications
                specifications={SPECS}
                model="Cyborg 15 A13UC"
                mpn="9S7-15K112-2423"
            />,
        );

        const rows = screen.getAllByRole('row');
        expect(rows[0].textContent).toContain('Model');
        expect(rows[0].textContent).toContain('Cyborg 15 A13UC');
        expect(rows[1].textContent).toContain('9S7-15K112-2423');
    });

    it('omits the row a shop has not filled in', () => {
        render(
            <ProductSpecifications
                specifications={SPECS}
                model="X"
                mpn={null}
            />,
        );

        expect(screen.getByText('Model')).toBeTruthy();
        expect(screen.queryByText('Part number')).toBeNull();
    });

    it('treats whitespace as not filled in', () => {
        render(<ProductSpecifications specifications={SPECS} mpn="   " />);

        expect(screen.queryByText('Part number')).toBeNull();
    });

    /*
     * A product with no spec sheet but a part number still has a table worth
     * drawing — before this the identifiers were shown only alongside specs,
     * so the one useful row was hidden by the absence of the others.
     */
    it('draws the table for identifiers alone', () => {
        render(<ProductSpecifications specifications={[]} mpn="ABC-123" />);

        expect(screen.getByText('ABC-123')).toBeTruthy();
        expect(
            screen.queryByText(/have not published a specification sheet/),
        ).toBeNull();
    });

    it('still says so when there is nothing at all', () => {
        render(<ProductSpecifications specifications={[]} />);

        expect(
            screen.getByText(/have not published a specification sheet/),
        ).toBeTruthy();
    });

    it('keeps the shop’s own grouped specs below them', () => {
        render(
            <ProductSpecifications
                specifications={SPECS}
                model="Cyborg 15 A13UC"
            />,
        );

        expect(screen.getByText('Display')).toBeTruthy();
        expect(screen.getByText('15.6"')).toBeTruthy();
    });
});
