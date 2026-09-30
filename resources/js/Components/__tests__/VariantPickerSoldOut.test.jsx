import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const visit = vi.fn();
const addToCart = vi.fn();
const getProductBySlug = vi.fn();
const closePicker = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { visit: (...a) => visit(...a) },
}));
vi.mock('../Toast', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('../../services', () => ({
    cartService: { addToCart: (...a) => addToCart(...a) },
    productService: { getProductBySlug: (...a) => getProductBySlug(...a) },
}));
vi.mock('../../store/useAppStore', () => {
    const state = {
        variantPicker: {
            slug: 'iphone-15-pro-max',
            name: 'iPhone 15 Pro Max',
            thenCheckout: false,
        },
        closeVariantPicker: (...a) => closePicker(...a),
        fetchCartCount: vi.fn(),
    };
    const store = (pick) => pick(state);
    store.getState = () => state;
    return { default: store };
});

const { default: VariantPickerModal } = await import('../VariantPickerModal');

const option = (id, name, price, inStock) => ({
    id,
    name,
    effective_price: price,
    in_stock: inStock,
});

/**
 * The option window a card opens. Sold-out options were disabled and faded to
 * half, so they looked broken — and "Add to cart" stayed live for one, which
 * the server then refused.
 */
describe('choosing an option from a card', () => {
    beforeEach(() => {
        visit.mockReset();
        closePicker.mockReset();
        addToCart.mockReset().mockResolvedValue({});
    });

    it('lets a sold-out option be chosen, and offers Notify me for it', async () => {
        getProductBySlug.mockResolvedValue({
            id: 1311,
            name: 'iPhone 15 Pro Max',
            active_variants: [
                option(1, '512GB / Natural Titanium', 206500, false),
                option(2, '1TB / Natural Titanium', 228000, false),
            ],
        });
        const user = userEvent.setup();
        render(<VariantPickerModal />);

        const oneTb = await screen.findByRole('button', { name: /1TB/ });
        expect(oneTb).not.toBeDisabled();

        await user.click(oneTb);
        expect(oneTb).toHaveAttribute('aria-pressed', 'true');

        expect(
            screen.queryByRole('button', { name: 'Add to cart' }),
        ).toBeNull();
        await user.click(screen.getByRole('button', { name: 'Notify me' }));

        expect(addToCart).not.toHaveBeenCalled();
        expect(closePicker).toHaveBeenCalled();
        expect(visit).toHaveBeenCalledWith(
            expect.stringMatching(/iphone-15-pro-max#notify$/),
        );
    });

    // The layout keeps the window mounted across a visit, so it has to shut
    // itself, or it stays open over the product page.
    it('closes itself when Full details opens the product page', async () => {
        getProductBySlug.mockResolvedValue({
            id: 1311,
            name: 'iPhone 15 Pro Max',
            active_variants: [
                option(1, '512GB / Natural Titanium', 206500, true),
            ],
        });
        const user = userEvent.setup();
        render(<VariantPickerModal />);

        await user.click(
            await screen.findByRole('button', { name: 'Full details' }),
        );

        expect(closePicker).toHaveBeenCalled();
        expect(visit).toHaveBeenCalledWith(
            expect.stringMatching(/iphone-15-pro-max$/),
        );
    });

    it('still adds an option that is in stock', async () => {
        getProductBySlug.mockResolvedValue({
            id: 1311,
            name: 'iPhone 15 Pro Max',
            active_variants: [
                option(1, '512GB / Natural Titanium', 206500, true),
                option(2, '1TB / Natural Titanium', 228000, false),
            ],
        });
        const user = userEvent.setup();
        render(<VariantPickerModal />);

        await user.click(
            await screen.findByRole('button', { name: 'Add to cart' }),
        );

        await waitFor(() => expect(addToCart).toHaveBeenCalledWith(1311, 1, 1));
    });
});
