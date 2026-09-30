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
 * A product that fails to load is not a product that does not exist. A
 * timeout on a slow line said "Product not found or unavailable" about a
 * product that was in stock.
 */
describe('when the product does not load', () => {
    const PRODUCT = {
        id: 5,
        name: 'QA Mouse',
        slug: 'qa-mouse',
        price: 1200,
        in_stock: true,
        images: [],
        specifications: [],
        category: { name: 'Mouse', slug: 'mouse' },
    };

    beforeEach(() => vi.clearAllMocks());

    it('says it could not load, and Try again loads it', async () => {
        getProductBySlug
            .mockRejectedValueOnce({ status: 0, message: 'timeout' })
            .mockResolvedValueOnce({ data: PRODUCT });
        const user = userEvent.setup();
        render(<ProductDetails productSlug="qa-mouse" />);

        expect(
            await screen.findByText(/couldn’t load this product/),
        ).toBeInTheDocument();
        expect(screen.queryByText(/not found/)).toBeNull();

        await user.click(screen.getByRole('button', { name: 'Try again' }));

        await waitFor(() =>
            expect(
                screen.getByRole('heading', { name: 'QA Mouse' }),
            ).toBeInTheDocument(),
        );
    });

    it('says not found only when there is no such product', async () => {
        getProductBySlug.mockRejectedValueOnce({ status: 404 });
        render(<ProductDetails productSlug="gone" />);

        expect(
            await screen.findByText('Product not found or unavailable.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
    });
});
