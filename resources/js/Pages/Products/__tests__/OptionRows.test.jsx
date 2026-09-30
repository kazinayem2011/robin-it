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
 * A product that varies two ways — storage and colour — shows a row for each,
 * the way StarTech does, instead of eight "512GB / Natural Titanium" chips.
 */
describe('options in rows', () => {
    const variant = (id, storage, colour, price, inStock) => ({
        id,
        name: `${storage} / ${colour}`,
        options: { 'RAM / Storage': storage, Color: colour },
        price,
        effective_price: price,
        in_stock: inStock,
        swatch: colour.startsWith('Blue') ? '#3d5a80' : null,
        image_url: `/img/${colour.split(' ')[0].toLowerCase()}.jpg`,
        images: [],
    });

    const PRODUCT = {
        id: 1311,
        name: 'iPhone 15 Pro Max',
        slug: 'iphone-15-pro-max',
        price: 279999,
        in_stock: true,
        has_variants: true,
        variant_attributes: ['RAM / Storage', 'Color'],
        active_variants: [
            variant(6, '512GB', 'Natural Titanium', 206500, true),
            variant(7, '1TB', 'Natural Titanium', 228000, false),
            variant(8, '512GB', 'Blue Titanium', 206500, true),
            variant(9, '512GB', 'Black Titanium', 206500, false),
            variant(10, '1TB', 'Blue Titanium', 228000, true),
        ],
        images: [],
        specifications: [],
        category: { name: 'iPhone', slug: 'iphone' },
    };

    beforeEach(() => {
        vi.clearAllMocks();
        getProductBySlug.mockResolvedValue({ data: PRODUCT });
    });

    it('gives each choice its own row, and keeps the other when one changes', async () => {
        const user = userEvent.setup();
        render(<ProductDetails productSlug="iphone-15-pro-max" />);

        const blue = await screen.findByRole('button', {
            name: 'Blue Titanium',
        });
        expect(screen.getByText('Color')).toBeInTheDocument();
        // A colour with a swatch shows it; one without, and storage, do not.
        expect(
            screen
                .getByRole('button', { name: '512GB' })
                .querySelector('.option-swatch'),
        ).toBeNull();
        expect(blue.querySelector('.option-swatch')).not.toBeNull();
        expect(
            screen
                .getByRole('button', { name: 'Natural Titanium' })
                .querySelector('.option-swatch'),
        ).toBeNull();
        expect(screen.queryByRole('button', { name: /512GB \// })).toBeNull();

        await user.click(blue);
        await user.click(screen.getByRole('button', { name: '1TB' }));

        // 1TB with Blue kept, not the first 1TB in the list.
        await waitFor(() =>
            expect(screen.getByRole('button', { name: '1TB' })).toHaveAttribute(
                'aria-pressed',
                'true',
            ),
        );
        expect(blue).toHaveAttribute('aria-pressed', 'true');
        expect(document.body.textContent).toMatch(/2,28,000/);
    });

    it('says which colour is sold out with the storage chosen, and still lets it be picked', async () => {
        const user = userEvent.setup();
        render(<ProductDetails productSlug="iphone-15-pro-max" />);

        const black = await screen.findByRole('button', {
            name: /Black Titanium/,
        });
        expect(black).toHaveTextContent(/Sold out/i);
        expect(black).not.toBeDisabled();

        await user.click(black);
        await waitFor(() =>
            expect(black).toHaveAttribute('aria-pressed', 'true'),
        );
    });
});
