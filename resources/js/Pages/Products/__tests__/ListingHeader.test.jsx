import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import userEvent from '@testing-library/user-event';

const getProducts = vi.fn();
const getFilters = vi.fn();
const getCategoryBrands = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }) => <a href={href}>{children}</a>,
    router: { reload: vi.fn() },
    usePage: () => ({ props: {}, url: '/shop/office-equipment' }),
}));

vi.mock('../../../Layouts/MainLayout', () => ({ mainLayout: (page) => page }));

vi.mock('../../../services', () => ({
    productService: { getProducts, getFilters, getCategoryBrands },
}));

vi.mock('../../../hooks', () => ({
    useWishlist: () => ({ isWishlisted: () => false, toggle: vi.fn() }),
    useAddToCart: () => ({ addToCart: vi.fn(), isAdding: false }),
}));

const { default: ProductListing } = await import('../Index');

/**
 * The shelf's name over its own results, and nothing else up top.
 *
 * The heading area held the breadcrumb, the shelf name again as an <h1>, and
 * "Showing 20 of 51 items" — the page named twice and a count where nobody
 * looks for one. The name now sits over the grid it names, beside the two
 * controls that change how the grid is presented.
 */
describe('the listing header', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getProducts.mockResolvedValue({
            items: [],
            meta: { last_page: 3, total: 51 },
        });
        getFilters.mockResolvedValue({
            min_price: 500,
            max_price: 132000,
            total: 51,
            brands: [],
        });
        getCategoryBrands.mockResolvedValue([]);
    });

    const draw = () =>
        render(<ProductListing categorySlug="office-equipment" />);

    it('keeps the breadcrumb', async () => {
        draw();

        expect(await screen.findByRole('link', { name: 'Home' })).toBeTruthy();
        expect(document.querySelector('.breadcrumbs')).toBeTruthy();
    });

    /* The count is what was asked to go, and it took the second title with it. */
    it('no longer says how many of how many', async () => {
        draw();

        await waitFor(() => expect(getProducts).toHaveBeenCalled());

        expect(screen.queryByText(/Showing/)).toBeNull();
        expect(screen.queryByText(/items/)).toBeNull();
    });

    it('names the shelf once, over its own results', async () => {
        draw();

        const title = await waitFor(() => {
            const node = document.querySelector('.plp-results-title');
            expect(node).toBeTruthy();
            return node;
        });

        expect(title.textContent.toLowerCase()).toContain('office equipment');
        expect(document.querySelectorAll('.plp-results-title')).toHaveLength(1);
    });

    it('puts Show and Sort By beside the name', async () => {
        draw();

        await waitFor(() =>
            expect(document.querySelector('.plp-results-header')).toBeTruthy(),
        );

        expect(screen.getByText('Show:')).toBeTruthy();
        expect(screen.getByText('Sort By:')).toBeTruthy();
        expect(screen.getByLabelText('Products per page')).toBeTruthy();
        expect(screen.getByLabelText('Sort products by')).toBeTruthy();
    });

    /*
     * 60 is the API's ceiling. Offering more answered 422 and drew an empty
     * grid, so the menu must not drift above it.
     */
    it('offers no page size the API will refuse', async () => {
        const user = userEvent.setup();
        draw();

        /*
         * Select is a listbox rather than a <select> — the native control
         * cannot be styled — so the sizes only exist once it is opened.
         */
        await user.click(
            await waitFor(() => screen.getByLabelText('Products per page')),
        );

        const sizes = screen
            .getAllByRole('option')
            .map((node) => Number(node.textContent.trim()));

        expect(sizes).toEqual([20, 40, 60]);
    });

    it('asks the API for the size that is chosen', async () => {
        draw();

        await waitFor(() => expect(getProducts).toHaveBeenCalled());

        expect(getProducts.mock.calls[0][0]).toEqual(
            expect.objectContaining({ per_page: 20 }),
        );
    });
});
