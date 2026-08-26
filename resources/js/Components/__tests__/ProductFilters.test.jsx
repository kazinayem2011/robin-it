import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import ProductFilters from '../ProductFilters';

/*
 * Inertia's Link needs a router; the filter only uses it for hrefs.
 */
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
}));

const FACETS = {
    min_price: 1000,
    max_price: 90000,
    total: 12,
    categories: [
        { id: 1, name: 'Laptops', slug: 'laptops', count: 5, children: [] },
        { id: 2, name: 'Monitors', slug: 'monitors', count: 7, children: [] },
    ],
    brands: [
        { id: 1, name: 'ASUS', slug: 'asus' },
        { id: 2, name: 'MSI', slug: 'msi' },
    ],
};

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
        expect(screen.getByText('Laptops')).toBeInTheDocument();
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
