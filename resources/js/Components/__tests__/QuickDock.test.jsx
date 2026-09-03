import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

let state = { cartCount: 0, compareCount: 0 };

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

vi.mock('../../store/useAppStore', () => ({
    default: (selector) => selector(state),
}));

const { default: QuickDock } = await import('../QuickDock');

/**
 * Compare and cart, pinned to the side of the page.
 *
 * They were two of seven controls in the header's tool row, and they are the
 * two carrying a running count — which a shopper checks while scrolling a
 * listing, exactly when the header has been scrolled away.
 */
describe('QuickDock', () => {
    const withCounts = (cart, compare) => {
        state = { cartCount: cart, compareCount: compare };
        render(<QuickDock />);
    };

    it('offers both, wherever the counts stand', () => {
        withCounts(0, 0);

        expect(screen.getByRole('link', { name: /^compare$/i })).toBeTruthy();
        expect(screen.getByRole('link', { name: /^cart$/i })).toBeTruthy();
    });

    /* A nought says nothing a shopper needs, and two of them turn a small
       control into a cluttered one. */
    it('shows no badge when there is nothing to count', () => {
        const { container } = render(<QuickDock />);

        expect(container.querySelectorAll('.quick-dock-count')).toHaveLength(0);
    });

    it('counts what is in each', () => {
        withCounts(3, 2);

        expect(
            screen.getByRole('link', { name: /cart, 3 items/i }),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: /compare, 2 items/i }),
        ).toBeTruthy();
    });

    it('points at the right pages', () => {
        withCounts(1, 1);

        expect(
            screen.getByRole('link', { name: /compare/i }).getAttribute('href'),
        ).toBe('/compare');
        expect(
            screen.getByRole('link', { name: /cart/i }).getAttribute('href'),
        ).toBe('/cart');
    });
});
