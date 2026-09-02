import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const visit = vi.fn();
const addToCart = vi.fn(() => Promise.resolve({}));
const openVariantPicker = vi.fn();
const fetchCartCount = vi.fn();

vi.mock('@inertiajs/react', () => ({ router: { visit: (...a) => visit(...a) } }));

vi.mock('../../services', () => ({
    cartService: { addToCart: (...a) => addToCart(...a) },
}));

vi.mock('../../Components/Toast', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('../../store/useAppStore', () => ({
    default: {
        getState: () => ({ openVariantPicker, fetchCartCount }),
    },
}));

const { useAddToCart } = await import('../useAddToCart');

/**
 * What a card does when the product is sold by option.
 *
 * The server is asked for a product and an option and refuses a product alone,
 * so a card cannot add one blind. This used to answer that by navigating to the
 * product page — which loses the shopper's place in the list they were reading:
 * the filters, the scroll, the page. It raises a picker over the list instead.
 */
describe('useAddToCart', () => {
    beforeEach(() => vi.clearAllMocks());

    const Harness = ({ product, opts }) => {
        const add = useAddToCart();

        return (
            <button type="button" onClick={() => add(product, opts)}>
                add
            </button>
        );
    };

    const click = async (product, opts) => {
        const person = userEvent.setup();
        render(<Harness product={product} opts={opts} />);
        await person.click(screen.getByRole('button', { name: 'add' }));
    };

    it('adds a plain product straight to the cart', async () => {
        await click({ id: 7, name: 'Mouse', slug: 'mouse' });

        expect(addToCart).toHaveBeenCalledWith(7, 1);
        expect(openVariantPicker).not.toHaveBeenCalled();
        expect(visit).not.toHaveBeenCalled();
    });

    it('raises the picker for a product sold by option, instead of navigating', async () => {
        await click({
            id: 9,
            name: 'RAM',
            slug: 'ram-kit',
            has_variants: true,
        });

        expect(openVariantPicker).toHaveBeenCalledWith({
            slug: 'ram-kit',
            name: 'RAM',
            thenCheckout: false,
        });

        // Nothing is in the cart, and the shopper has not been sent anywhere.
        expect(addToCart).not.toHaveBeenCalled();
        expect(visit).not.toHaveBeenCalled();
    });

    /* Buy Now and the cart icon differ only in where they go afterwards, and
       that is knowable only at the click. */
    it('tells the picker when checkout is where this was heading', async () => {
        await click(
            { id: 9, name: 'RAM', slug: 'ram-kit', has_variants: true },
            { thenCheckout: true },
        );

        expect(openVariantPicker).toHaveBeenCalledWith(
            expect.objectContaining({ thenCheckout: true }),
        );
    });

    it('reports false for an option product, so Buy Now does not navigate', async () => {
        const results = [];
        const Probe = () => {
            const add = useAddToCart();

            return (
                <button
                    type="button"
                    onClick={async () =>
                        results.push(
                            await add({
                                id: 9,
                                slug: 'ram-kit',
                                has_variants: true,
                            }),
                        )
                    }
                >
                    go
                </button>
            );
        };

        const person = userEvent.setup();
        render(<Probe />);
        await person.click(screen.getByRole('button', { name: 'go' }));

        expect(results).toEqual([false]);
    });
});
