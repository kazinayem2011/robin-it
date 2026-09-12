import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { visit: vi.fn() },
    usePage: () => ({ props: {}, url: '/' }),
}));
vi.mock('@/services', () => ({
    cartService: { add: vi.fn() },
    compareService: { add: vi.fn() },
    wishlistService: { add: vi.fn() },
}));
vi.mock('@/Components/Toast', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const { default: ProductCard } = await import('../ProductCard');

/**
 * What a discount badge says.
 *
 * It said "-15% OFF". A percentage has to be worked against a price the badge
 * does not show, so the same badge on an ৳8,000 motherboard and a ৳285,000
 * laptop looks identical and is forty thousand taka apart. The trade here
 * quotes the saving, and so does the shop this one is modelled on.
 */
describe('the discount badge', () => {
    const product = (overrides = {}) => ({
        id: 1,
        name: 'Ryzen Gaming PC',
        slug: 'ryzen-gaming-pc',
        raw_price: 46200,
        raw_old_price: 47400,
        inStock: true,
        stockQuantity: 5,
        specs: [],
        ...overrides,
    });

    it('says how much is saved, not what fraction', () => {
        render(<ProductCard product={product()} />);

        expect(screen.getByText(/Save ৳1,200/)).toBeInTheDocument();
    });

    it('never states a percentage', () => {
        render(<ProductCard product={product()} />);

        expect(screen.queryByText(/%/)).not.toBeInTheDocument();
    });

    /* The flash card carries the same figure behind its flame. */
    it('says the same on a flash card', () => {
        render(<ProductCard product={product()} variant="flash" />);

        expect(screen.getByText(/Save ৳1,200/)).toBeInTheDocument();
    });

    /* No discount is no badge, rather than a badge saying nothing was saved. */
    it('shows nothing when the price is not cut', () => {
        render(<ProductCard product={product({ raw_old_price: 46200 })} />);

        expect(screen.queryByText(/Save/)).not.toBeInTheDocument();
    });

    /* Rounded and grouped the way every other figure in the shop is. */
    it('writes the figure the way the shop writes money', () => {
        render(
            <ProductCard
                product={product({ raw_price: 120000, raw_old_price: 145000 })}
            />,
        );

        expect(screen.getByText(/Save ৳25,000/)).toBeInTheDocument();
    });
});
