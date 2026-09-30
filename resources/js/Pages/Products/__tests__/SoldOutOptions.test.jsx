import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getProductBySlug = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: null } }, url: '/products/x' }),
}));
vi.mock('../../../Layouts/MainLayout', () => ({ mainLayout: (page) => page }));
vi.mock('../../../Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('../../../hooks', () => ({
    useWishlist: () => ({
        wishlistIds: [],
        toggleWishlist: vi.fn(),
        pendingId: null,
    }),
}));
vi.mock('../../../store/useAppStore', () => {
    const store = () => ({});
    store.getState = () => ({
        fetchCartCount: vi.fn(),
        setCompareCount: vi.fn(),
    });
    return { default: store };
});
vi.mock('@/services', () => ({
    productService: { getProductBySlug: (...a) => getProductBySlug(...a) },
    cartService: { add: vi.fn() },
    compareService: { add: vi.fn(), list: vi.fn().mockResolvedValue([]) },
    reviewService: {
        getProductReviews: vi.fn().mockResolvedValue({ data: [] }),
    },
}));

/* The pieces below the gallery are not what this is about. */
vi.mock('../../../Components/ProductSuggestions', () => ({
    default: () => null,
}));
vi.mock('../../../Components/ProductQuestions', () => ({
    default: () => null,
}));
vi.mock('../../../Components/ReviewList', () => ({ default: () => null }));
vi.mock('../../../Components/ReviewForm', () => ({ default: () => null }));
vi.mock('../../../Components/RatingBreakdown', () => ({ default: () => null }));
vi.mock('../../../Components/BackInStockForm', () => ({ default: () => null }));

const { default: ProductDetails } = await import('../Show');

/**
 * A sold-out option is still an option to look at.
 *
 * The buttons were disabled, faded and struck through, so a shopper could not
 * open a configuration that was out of stock to see its price — on a laptop
 * sold in three builds, all out of stock, nothing could be read at all.
 */
describe('sold-out options', () => {
    const variant = (id, name, price) => ({
        id,
        name,
        options: {},
        price,
        effective_price: price,
        in_stock: false,
        stock_quantity: 0,
    });

    const PRODUCT = {
        id: 1307,
        name: 'Lenovo IdeaPad Slim 3 15IRH10',
        slug: 'lenovo-ideapad-slim-3-15irh10',
        price: 105600,
        in_stock: false,
        has_variants: true,
        variant_attributes: ['Processor', 'Storage'],
        active_variants: [
            variant(1, 'Core i5-13420H / 512GB SSD', 98100),
            variant(2, 'Core i5-13420H / 1TB SSD', 105500),
            variant(3, 'Core i7-13620H / 512GB SSD', 107900),
        ],
        images: [],
        specifications: [],
        category: { name: 'Laptop', slug: 'laptop' },
    };

    beforeEach(() => {
        vi.clearAllMocks();
        getProductBySlug.mockResolvedValue({ data: PRODUCT });
    });

    it('can be chosen, and says it is sold out', async () => {
        const user = userEvent.setup();
        render(<ProductDetails productSlug="lenovo-ideapad-slim-3-15irh10" />);

        const i7 = await screen.findByRole('button', {
            name: /Core i7-13620H/,
        });

        expect(i7).not.toBeDisabled();
        expect(i7).toHaveTextContent(/Sold out/i);

        await user.click(i7);

        await waitFor(() => expect(i7).toHaveAttribute('aria-pressed', 'true'));
    });
});
