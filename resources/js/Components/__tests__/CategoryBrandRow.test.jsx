import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const { default: CategoryBrandRow } = await import('../CategoryBrandRow');

/**
 * The makers on a shelf, in a line across the top of it.
 *
 * Each is a shelf with its own address rather than a filter, which is the
 * whole reason it is not part of the sidebar.
 */
describe('CategoryBrandRow', () => {
    const BRANDS = [
        { id: 1, name: 'Lenovo', slug: 'lenovo-all-laptop' },
        { id: 2, name: 'MSI', slug: 'msi-all-laptop' },
        { id: 3, name: 'ASUS', slug: 'asus-all-laptop' },
    ];

    it('links each maker to its own shelf', () => {
        render(<CategoryBrandRow brands={BRANDS} />);

        expect(
            screen.getByRole('link', { name: 'ASUS' }).getAttribute('href'),
        ).toBe('/shop/asus-all-laptop');
    });

    it('keeps the order it was given', () => {
        render(<CategoryBrandRow brands={BRANDS} />);

        expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual([
            'Lenovo',
            'MSI',
            'ASUS',
        ]);
    });

    /* A row of one is not a choice; a row of none is a heading over nothing. */
    it('draws nothing when there is no choice to make', () => {
        const { container } = render(<CategoryBrandRow brands={[BRANDS[0]]} />);
        expect(container.querySelector('.cat-brand-row')).toBeNull();
    });

    it('draws nothing when the row is empty or missing', () => {
        expect(
            render(<CategoryBrandRow brands={[]} />).container.textContent,
        ).toBe('');
        expect(render(<CategoryBrandRow />).container.textContent).toBe('');
    });

    /* Standing on a maker's own page, that pill is where you already are. */
    it('marks the shelf being viewed', () => {
        render(
            <CategoryBrandRow brands={BRANDS} activeSlug="msi-all-laptop" />,
        );

        const msi = screen.getByRole('link', { name: 'MSI' });
        expect(msi.className).toContain('is-active');
        expect(msi.getAttribute('aria-current')).toBe('page');

        expect(
            screen
                .getByRole('link', { name: 'ASUS' })
                .getAttribute('aria-current'),
        ).toBeNull();
    });

    it('is announced as what it is', () => {
        render(<CategoryBrandRow brands={BRANDS} />);

        expect(
            screen.getByRole('navigation', { name: 'Brands in this category' }),
        ).toBeTruthy();
    });
});
