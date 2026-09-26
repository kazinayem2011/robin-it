import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// What usePage hands the card; a test can sign somebody in.
let pageProps = {};

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { visit: vi.fn() },
    usePage: () => ({ props: pageProps, url: '/' }),
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
 * How a card says whether it can be bought.
 *
 * Through the price slot and the button: "Buy Now" under a price when it can
 * be bought; "Sold Out" once, in the price's place, when it cannot, with the
 * button offering the next step — "Notify me", to the product page's
 * back-in-stock form. It showed "Sold Out" twice, the second on a disabled
 * button that did nothing. No rating row, no stock bar.
 */
describe.each(['standard', 'flash'])('a %s card', (variant) => {
    beforeEach(() => {
        pageProps = {};
    });

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

    const soldOut = { inStock: false, stockQuantity: 0 };

    const card = (overrides) =>
        render(<ProductCard product={product(overrides)} variant={variant} />);

    // The main button under the price; the icon rail carries the same label.
    const mainButton = () => document.querySelector('button.btn-add-cart');
    const notifyLink = () => document.querySelector('a.btn-add-cart.is-notify');

    it('shows the price and Buy Now when it is in stock', () => {
        card();

        expect(screen.getByText('৳78,000')).toBeInTheDocument();
        expect(mainButton()).toHaveTextContent('Buy Now');
        expect(mainButton()).toBeEnabled();
        expect(screen.queryByText('Sold Out')).not.toBeInTheDocument();
        expect(notifyLink()).toBeNull();
    });

    it('says Sold Out once, in place of the price, when it is not', () => {
        card(soldOut);

        expect(screen.queryByText(/৳78,000/)).not.toBeInTheDocument();
        expect(screen.queryByText(/৳81,500/)).not.toBeInTheDocument();
        expect(screen.getAllByText('Sold Out')).toHaveLength(1);
        expect(mainButton()).toBeNull();
    });

    it('offers Notify me, to the back-in-stock form', () => {
        card(soldOut);

        expect(notifyLink()).toHaveTextContent('Notify me');
        expect(notifyLink()).toHaveAttribute(
            'href',
            expect.stringMatching(/hp-15-fc0626au#notify$/),
        );
    });

    /* The list takes a mobile number too, so an account that is only a
       number — the usual kind here — can join it. */
    it('offers it to an account with only a mobile number', () => {
        pageProps = {
            auth: { user: { id: 7, email: null, phone: '01711223344' } },
        };
        card(soldOut);

        expect(notifyLink()).toHaveTextContent('Notify me');
        expect(screen.getAllByText('Sold Out')).toHaveLength(1);
    });

    it('offers it to a signed-in customer with an email address', () => {
        pageProps = { auth: { user: { id: 7, email: 'a@b.test' } } };
        card(soldOut);

        expect(notifyLink()).toHaveTextContent('Notify me');
    });

    /* The shop's own words when it set them on the product. */
    it('says what the product says when it has its own wording', () => {
        card({ ...soldOut, out_of_stock_status: 'Up Coming' });

        expect(screen.getByText('Up Coming')).toBeInTheDocument();
        expect(screen.queryByText('Sold Out')).not.toBeInTheDocument();
    });

    /* Money off something that cannot be bought is not an offer. */
    it('drops the Save badge with the price when it is sold out', () => {
        card(soldOut);

        expect(screen.queryByText(/Save/)).not.toBeInTheDocument();
    });

    it('keeps the Save badge while it can be bought', () => {
        card();

        expect(screen.getByText(/Save ৳3,500/)).toBeInTheDocument();
    });

    /* A pre-order can still be bought, so it keeps its price. */
    it('keeps the price on a pre-order', () => {
        card({ ...soldOut, preorder: true });

        expect(screen.getByText('৳78,000')).toBeInTheDocument();
        expect(mainButton()).toHaveTextContent('Pre-order');
        expect(mainButton()).toBeEnabled();
        expect(notifyLink()).toBeNull();
    });

    it('has no rating row or stock bar', () => {
        card(soldOut);

        expect(screen.queryByText(/reviews? yet/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/\bSold$|0 Sold/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Available:/)).not.toBeInTheDocument();
    });
});
