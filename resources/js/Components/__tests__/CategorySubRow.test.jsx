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

const { default: CategorySubRow } = await import('../CategorySubRow');

/**
 * The shelves one level down, across the top of a category page, as Star Tech
 * lays it out: Projector, Conference System… on Office Equipment.
 */
describe('CategorySubRow', () => {
    const SHELVES = [
        { id: 1, name: 'Projector', slug: 'projector' },
        { id: 2, name: 'Conference System', slug: 'conference-system' },
        { id: 3, name: 'PA System', slug: 'pa-system' },
    ];

    it('links each shelf to its own page', () => {
        render(<CategorySubRow categories={SHELVES} />);

        expect(
            screen
                .getByRole('link', { name: 'PA System' })
                .getAttribute('href'),
        ).toBe('/shop/pa-system');
    });

    it('keeps the order it was given', () => {
        render(<CategorySubRow categories={SHELVES} />);

        expect(screen.getAllByRole('link').map((a) => a.textContent)).toEqual([
            'Projector',
            'Conference System',
            'PA System',
        ]);
    });

    /* One shelf below is still a way in, unlike a row of one brand was. */
    it('shows a single shelf', () => {
        render(<CategorySubRow categories={[SHELVES[0]]} />);

        expect(
            screen.getByRole('link', { name: 'Projector' }),
        ).toBeInTheDocument();
    });

    /* A shelf with none below it has no row, as on Star Tech. */
    it('draws nothing when there is nothing below', () => {
        expect(
            render(<CategorySubRow categories={[]} />).container.textContent,
        ).toBe('');
        expect(render(<CategorySubRow />).container.textContent).toBe('');
    });
});
