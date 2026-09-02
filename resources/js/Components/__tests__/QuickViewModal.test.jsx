import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const addToCart = vi.fn(() => Promise.resolve({}));
const openVariantPicker = vi.fn();
const fetchCartCount = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Link: ({ children }) => <span>{children}</span>,
}));

vi.mock('../../services', () => ({
    cartService: { addToCart: (...a) => addToCart(...a) },
}));

vi.mock('../Toast', () => ({
    toast: {
        error: (...a) => toastError(...a),
        success: (...a) => toastSuccess(...a),
    },
}));

vi.mock('../../store/useAppStore', () => ({
    default: { getState: () => ({ openVariantPicker, fetchCartCount }) },
}));

const { default: QuickViewModal } = await import('../QuickViewModal');

/**
 * Adding to the cart from quick view.
 *
 * It sent the same request for every product, including one sold by option —
 * which the server refuses, because it is asked for a product and an option and
 * given only a product. The refusal was then reported as "Failed to add product
 * to cart", discarding the server's own explanation, so the button looked
 * broken rather than unfinished.
 */
describe('QuickViewModal', () => {
    beforeEach(() => vi.clearAllMocks());

    const open = (product) =>
        render(
            <QuickViewModal show onClose={() => {}} product={product} />,
        );

    const plain = {
        id: 3,
        name: 'Logitech MX Master',
        slug: 'mx-master',
        price: 9500,
        stock_quantity: 8,
    };

    it('adds a plain product with the chosen quantity', async () => {
        const person = userEvent.setup();
        open(plain);

        await person.click(
            screen.getByRole('button', { name: /increase quantity/i }),
        );
        await person.click(screen.getByRole('button', { name: /add to cart/i }));

        expect(addToCart).toHaveBeenCalledWith(3, 2);
    });

    it('sends an option product to the picker rather than a doomed request', async () => {
        const person = userEvent.setup();
        open({ ...plain, has_variants: true });

        await person.click(
            screen.getByRole('button', { name: /choose options/i }),
        );

        expect(openVariantPicker).toHaveBeenCalledWith({
            slug: 'mx-master',
            name: 'Logitech MX Master',
            thenCheckout: false,
        });
        expect(addToCart).not.toHaveBeenCalled();
    });

    /* The server says which option is needed, or how many are left. Replacing
       that with one generic sentence is what made this unfixable by the
       shopper. */
    it('shows the server’s reason when the add is refused', async () => {
        addToCart.mockRejectedValueOnce({ message: 'Only 2 left in stock.' });

        const person = userEvent.setup();
        open(plain);

        await person.click(screen.getByRole('button', { name: /add to cart/i }));

        expect(toastError).toHaveBeenCalledWith(
            'Only 2 left in stock.',
            'Error',
        );
    });

    it('will not offer more than is in stock', async () => {
        const person = userEvent.setup();
        open({ ...plain, stock_quantity: 2 });

        const more = screen.getByRole('button', { name: /increase quantity/i });

        await person.click(more);
        expect(more).toBeDisabled();

        await person.click(screen.getByRole('button', { name: /add to cart/i }));
        expect(addToCart).toHaveBeenCalledWith(3, 2);
    });

    it('does not offer to sell something that is out of stock', () => {
        open({ ...plain, stock_quantity: 0 });

        expect(
            screen.getByRole('button', { name: /out of stock/i }),
        ).toBeDisabled();
    });

    /* The card was what was clicked to get here; the two must agree. */
    it('trusts an explicit inStock flag, the way the card does', () => {
        open({ ...plain, stock_quantity: 0, inStock: true });

        expect(
            screen.getByRole('button', { name: /add to cart/i }),
        ).not.toBeDisabled();
    });
});
