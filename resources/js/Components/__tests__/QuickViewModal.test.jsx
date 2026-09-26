import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const addToCart = vi.fn(() => Promise.resolve({}));
const openVariantPicker = vi.fn();
const fetchCartCount = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();
const getProductBySlug = vi.fn(() => Promise.resolve(null));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children }) => <span>{children}</span>,
}));

vi.mock('../../services', () => ({
    cartService: { addToCart: (...a) => addToCart(...a) },
    productService: { getProductBySlug: (...a) => getProductBySlug(...a) },
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

const { default: QuickViewModal, forgetQuickViewDetails } =
    await import('../QuickViewModal');

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
    beforeEach(() => {
        vi.clearAllMocks();
        forgetQuickViewDetails();
    });

    const open = (product) =>
        render(<QuickViewModal show onClose={() => {}} product={product} />);

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
        await person.click(
            screen.getByRole('button', { name: /add to cart/i }),
        );

        // The third argument is the option, null for a product without one.
        expect(addToCart).toHaveBeenCalledWith(3, 2, null);
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

        await person.click(
            screen.getByRole('button', { name: /add to cart/i }),
        );

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

        await person.click(
            screen.getByRole('button', { name: /add to cart/i }),
        );
        // The third argument is the option, null for a product without one.
        expect(addToCart).toHaveBeenCalledWith(3, 2, null);
    });

    it('does not offer to sell something that is out of stock', () => {
        open({ ...plain, stock_quantity: 0 });

        // Nothing to add: the next step is the back-in-stock form.
        expect(
            screen.queryByRole('button', { name: /add to cart/i }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Notify me')).toBeInTheDocument();
        expect(screen.getAllByText('Sold Out')).toHaveLength(1);
    });

    /* The card was what was clicked to get here; the two must agree. */
    it('trusts an explicit inStock flag, the way the card does', () => {
        open({ ...plain, stock_quantity: 0, inStock: true });

        expect(
            screen.getByRole('button', { name: /add to cart/i }),
        ).not.toBeDisabled();
    });

    /* What a card actually hands over: prices formatted, numbers beside. */
    const fromCard = {
        id: 9,
        name: 'HP 15-fc0626AU Laptop',
        slug: 'hp-15',
        price: '৳70,000',
        raw_price: 70000,
        oldPrice: '৳77,000',
        raw_old_price: 77000,
        inStock: true,
        specs: ['Processor: AMD Ryzen 3 7320U', 'RAM: 8GB, Storage: 512GB SSD'],
    };

    it('shows the price it was reduced from, which the card sends by another name', async () => {
        open(fromCard);

        expect(screen.getByText('৳70,000')).toBeInTheDocument();
        expect(screen.getByText('৳77,000')).toBeInTheDocument();
        await waitFor(() => expect(getProductBySlug).toHaveBeenCalled());
    });

    it('lists the key features the card carries', () => {
        open(fromCard);

        expect(
            screen.getByText('Processor: AMD Ryzen 3 7320U'),
        ).toBeInTheDocument();
        expect(screen.getByText('In Stock')).toBeInTheDocument();
    });

    it('fetches the product and shows a short summary of its description', async () => {
        getProductBySlug.mockResolvedValueOnce({
            name: 'HP 15-fc0626AU Laptop',
            description:
                '<b>HP 15-fc0626AU Laptop</b><div>The HP 15 is powered by a Ryzen 3. It boots quickly.</div>',
        });
        open(fromCard);

        expect(getProductBySlug).toHaveBeenCalledWith('hp-15');
        expect(
            await screen.findByText(
                'The HP 15 is powered by a Ryzen 3. It boots quickly.',
            ),
        ).toBeInTheDocument();
    });

    /* It said "Experience next-generation performance…" under everything. */
    it('says nothing rather than the same line for every product', async () => {
        open(fromCard);

        await waitFor(() => expect(getProductBySlug).toHaveBeenCalled());
        expect(
            screen.queryByText(/next-generation performance/i),
        ).not.toBeInTheDocument();
    });

    it('shows no price once it cannot be bought, as the card does', () => {
        open({ ...fromCard, inStock: false });

        expect(screen.queryByText('৳70,000')).not.toBeInTheDocument();
        expect(screen.getAllByText('Sold Out').length).toBeGreaterThan(0);
    });

    it('shows the short summary the shop wrote, above the description', async () => {
        getProductBySlug.mockResolvedValueOnce({
            name: 'HP 15-fc0626AU Laptop',
            short_description: 'Ryzen 3 7320U 15.6" FHD Copilot+PC Laptop',
            description: '<div>The HP 15 is powered by a Ryzen 3.</div>',
        });
        open(fromCard);

        expect(
            await screen.findByText(
                'Ryzen 3 7320U 15.6" FHD Copilot+PC Laptop',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('The HP 15 is powered by a Ryzen 3.'),
        ).toBeInTheDocument();
    });

    /* A placeholder product's one sentence was printed twice. */
    it('does not repeat a key-feature line as the summary', async () => {
        const line = 'Placeholder so the AI PC category appears in the menu.';
        getProductBySlug.mockResolvedValueOnce({
            name: 'Sample AI PC',
            short_description: line,
        });
        open({ ...fromCard, name: 'Sample AI PC', specs: [line] });

        await waitFor(() => expect(getProductBySlug).toHaveBeenCalled());
        await waitFor(() => expect(screen.getAllByText(line)).toHaveLength(1));
    });

    /* Opening the same product again: shown at once, not fetched again. */
    it('does not fetch a product twice in one visit', async () => {
        getProductBySlug.mockResolvedValue({
            name: 'HP 15-fc0626AU Laptop',
            description: '<div>The HP 15 is powered by a Ryzen 3.</div>',
        });
        const { rerender } = open(fromCard);
        await screen.findByText('The HP 15 is powered by a Ryzen 3.');

        rerender(
            <QuickViewModal
                show={false}
                onClose={() => {}}
                product={fromCard}
            />,
        );
        rerender(<QuickViewModal show onClose={() => {}} product={fromCard} />);

        expect(
            screen.getByText('The HP 15 is powered by a Ryzen 3.'),
        ).toBeInTheDocument();
        expect(getProductBySlug).toHaveBeenCalledTimes(1);
        getProductBySlug.mockResolvedValue(null);
    });
});
