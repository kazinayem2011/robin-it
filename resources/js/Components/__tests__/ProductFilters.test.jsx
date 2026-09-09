import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import userEvent from '@testing-library/user-event';
import ProductFilters from '../ProductFilters';

const FACETS = {
    min_price: 1000,
    max_price: 90000,
    total: 12,
    /*
     * Nine, because the brand search only appears above eight — below that the
     * list is shorter than the box that would filter it.
     */
    brands: [
        { id: 1, name: 'ASUS', slug: 'asus' },
        { id: 2, name: 'MSI', slug: 'msi' },
        { id: 3, name: 'Corsair', slug: 'corsair' },
        { id: 4, name: 'Dell', slug: 'dell' },
        { id: 5, name: 'HP', slug: 'hp' },
        { id: 6, name: 'Lenovo', slug: 'lenovo' },
        { id: 7, name: 'Gigabyte', slug: 'gigabyte' },
        { id: 8, name: 'Intel', slug: 'intel' },
        { id: 9, name: 'AMD', slug: 'amd' },
    ],
};

const headings = () =>
    [...document.querySelectorAll('.plp-filter-legend h4')].map((h) =>
        h.textContent.trim(),
    );

const body = () => document.querySelector('.plp-filters-body');
const skeletons = () =>
    document.querySelectorAll('.plp-filter-skeleton-rows').length;

describe('ProductFilters loading and busy states', () => {
    /*
     * The very first listing of a session has no facets at all, so there is
     * nothing to keep on screen and placeholders are the honest answer.
     */
    it('shows placeholders only when there is nothing to show', () => {
        render(<ProductFilters facets={null} value={{}} loading />);

        expect(skeletons()).toBe(2);
        expect(screen.queryByText('ASUS')).not.toBeInTheDocument();
    });

    /*
     * Refreshing counts must not replace what the shopper is reading. This is
     * the case that used to flash placeholders on every category click.
     */
    it('keeps the filters on screen while refreshing them', () => {
        render(<ProductFilters facets={FACETS} value={{}} busy />);

        expect(skeletons()).toBe(0);
        expect(screen.getByText('ASUS')).toBeInTheDocument();
    });

    it('takes itself out of action while refreshing', () => {
        render(<ProductFilters facets={FACETS} value={{}} busy />);

        expect(body()).toHaveClass('is-busy');
        expect(body()).toHaveAttribute('inert');
        expect(body()).toHaveAttribute('aria-busy', 'true');
    });

    it('is interactive again once the refresh lands', () => {
        const { rerender } = render(
            <ProductFilters facets={FACETS} value={{}} busy />,
        );

        rerender(<ProductFilters facets={FACETS} value={{}} busy={false} />);

        expect(body()).not.toHaveClass('is-busy');
        expect(body()).not.toHaveAttribute('inert');
    });

    /*
     * Placeholders win over dimming: dimming a skeleton reads as broken, and
     * there is nothing there to protect from a second click anyway.
     */
    it('does not dim the placeholders on a cold load', () => {
        render(<ProductFilters facets={null} value={{}} loading busy />);

        expect(skeletons()).toBe(2);
        expect(body()).not.toHaveClass('is-busy');
        expect(body()).not.toHaveAttribute('inert');
    });

    it('shows the filters plainly when idle', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(skeletons()).toBe(0);
        expect(body()).not.toHaveClass('is-busy');
        expect(screen.getByText('MSI')).toBeInTheDocument();
    });
});

/**
 * What the panel is for.
 *
 * It led with a category tree and a search box for it, which is navigation the
 * shop offers three other ways — the mega menu, the breadcrumb, and the row of
 * makers across the top of the shelf. A panel for narrowing a selection should
 * not open with the one control that leaves it.
 */
describe('what the filter panel offers', () => {
    it('opens on Price, not on Category', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(headings()[0]).toBe('Price');
        expect(headings()).not.toContain('Category');
    });

    it('offers Price, Brand and Availability', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(headings()).toEqual(
            expect.arrayContaining(['Price', 'Brand', 'Availability']),
        );
    });

    /* The category search went with the tree; the brand one is a different box. */
    it('has no category search left behind', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(screen.queryByLabelText('Search categories')).toBeNull();
        expect(screen.queryByPlaceholderText('Search categories')).toBeNull();
    });

    it('keeps the brand search', async () => {
        const user = userEvent.setup();
        render(<ProductFilters facets={FACETS} value={{}} />);

        const box = screen.getByLabelText('Search brands');
        await user.type(box, 'cor');

        expect(screen.getByText('Corsair')).toBeInTheDocument();
        expect(screen.queryByText('ASUS')).toBeNull();
    });

    /* Several at once: a shopper comparing ASUS against MSI picks both. */
    it('reports a brand as chosen, and two of them at once', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(
            <ProductFilters facets={FACETS} value={{}} onChange={onChange} />,
        );

        await user.click(screen.getByLabelText('ASUS'));
        expect(onChange).toHaveBeenLastCalledWith(
            expect.objectContaining({ brand_ids: [1] }),
        );

        onChange.mockClear();
        render(
            <ProductFilters
                facets={FACETS}
                value={{ brand_ids: [1] }}
                onChange={onChange}
            />,
        );

        await user.click(screen.getAllByLabelText('MSI')[1]);
        expect(onChange).toHaveBeenLastCalledWith(
            expect.objectContaining({ brand_ids: [1, 2] }),
        );
    });
});
