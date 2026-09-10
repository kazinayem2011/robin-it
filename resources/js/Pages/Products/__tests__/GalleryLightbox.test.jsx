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
 * The gallery's way into the photos.
 *
 * The main image is about 405px wide and was the largest view of a product the
 * shop had — while growing on hover, so it read as clickable and was not. This
 * mounts the real page and opens the real viewer, because the last time a
 * gallery bug was missed it was missed by mocking the component that had it.
 */
describe('the product gallery', () => {
    const PRODUCT = {
        id: 1,
        name: 'A Laptop',
        slug: 'a-laptop',
        price: 1000,
        stock_quantity: 5,
        in_stock: true,
        images: [
            { image_path: '/one.jpg' },
            { image_path: '/two.jpg' },
            { image_path: '/three.jpg' },
        ],
        active_variants: [],
        specifications: [],
        category: { name: 'Laptop', slug: 'laptop' },
    };

    beforeEach(() => {
        vi.clearAllMocks();
        getProductBySlug.mockResolvedValue({ data: PRODUCT });
    });

    const openPage = async () => {
        render(<ProductDetails productSlug="a-laptop" />);

        return screen.findByRole('button', {
            name: /View photos of A Laptop/i,
        });
    };

    it('offers the main image as a way in', async () => {
        expect(await openPage()).toBeTruthy();
    });

    it('opens the viewer when it is used', async () => {
        const user = userEvent.setup();
        const opener = await openPage();

        await user.click(opener);

        expect(
            await screen.findByRole('dialog', { name: /photos/i }),
        ).toBeTruthy();
    });

    it('shows every photo the product has', async () => {
        const user = userEvent.setup();
        await user.click(await openPage());

        await screen.findByRole('dialog', { name: /photos/i });

        expect(screen.getByText('1 of 3')).toBeTruthy();
    });

    it('closes again and leaves the page usable', async () => {
        const user = userEvent.setup();
        await user.click(await openPage());

        await screen.findByRole('dialog', { name: /photos/i });
        await user.click(screen.getByLabelText('Close photos'));

        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: /photos/i }),
            ).toBeNull(),
        );
        expect(document.body.style.overflow).toBe('unset');
    });
}, 20000);
