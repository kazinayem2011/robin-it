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
 * How a card says whether it can be bought, as StarTech's cards do.
 *
 * Only through the price slot and the button: "Buy Now" under a price when it
 * can be bought, "Sold Out" in the price's place and on the button when it
 * cannot. No rating row, no stock bar, no "Out of stock · 0 Sold" line.
 */
describe.each(['standard', 'flash'])('a %s card', (variant) => {
    const product = (overrides = {}) => ({
        id: 1,
        name: 'HP 15-fc0626AU',
        slug: 'hp-15-fc0626au',
        raw_price: 78000,
        raw_old_price: 81500,
        inStock: true,
        stockQuantity: 5,
        specs: ['Processor: AMD Ryzen 3 7320U'],
        ...overrides,
    });

    const card = (overrides) =>
        render(<ProductCard product={product(overrides)} variant={variant} />);

    // The main button under the price; the icon rail carries the same label.
    const mainButton = () => document.querySelector('button.btn-add-cart');

    it('shows the price and Buy Now when it is in stock', () => {
        card();

        expect(screen.getByText('৳78,000')).toBeInTheDocument();
        expect(mainButton()).toHaveTextContent('Buy Now');
        expect(mainButton()).toBeEnabled();
        expect(screen.queryByText('Sold Out')).not.toBeInTheDocument();
    });

    it('shows Sold Out in place of the price when it is not', () => {
        card({ inStock: false, stockQuantity: 0 });

        expect(screen.queryByText(/৳78,000/)).not.toBeInTheDocument();
        expect(screen.queryByText(/৳81,500/)).not.toBeInTheDocument();
        expect(mainButton()).toHaveTextContent('Sold Out');
        expect(mainButton()).toBeDisabled();
        expect(screen.getAllByText('Sold Out').length).toBeGreaterThan(1);
    });

    /* The shop's own words when it set them on the product. */
    it('says what the product says when it has its own wording', () => {
        card({
            inStock: false,
            stockQuantity: 0,
            out_of_stock_status: 'Up Coming',
        });

        expect(mainButton()).toHaveTextContent('Up Coming');
        expect(screen.queryByText('Sold Out')).not.toBeInTheDocument();
    });

    /* Money off something that cannot be bought is not an offer. */
    it('drops the Save badge with the price when it is sold out', () => {
        card({ inStock: false, stockQuantity: 0 });

        expect(screen.queryByText(/Save/)).not.toBeInTheDocument();
    });

    it('keeps the Save badge while it can be bought', () => {
        card();

        expect(screen.getByText(/Save ৳3,500/)).toBeInTheDocument();
    });

    /* A pre-order can still be bought, so it keeps its price. */
    it('keeps the price on a pre-order', () => {
        card({ inStock: false, stockQuantity: 0, preorder: true });

        expect(screen.getByText('৳78,000')).toBeInTheDocument();
        expect(mainButton()).toHaveTextContent('Pre-order');
        expect(mainButton()).toBeEnabled();
    });

    it('has no rating row or stock bar', () => {
        card({ inStock: false, stockQuantity: 0 });

        expect(screen.queryByText(/reviews? yet/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/\bSold$|0 Sold/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Available:/)).not.toBeInTheDocument();
    });
});
