import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const visit = vi.fn();
let currentUrl = '/';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: (...args) => visit(...args) },
    usePage: () => ({ url: currentUrl }),
}));

vi.mock('../../services', () => ({
    productService: {
        getSearchSuggestions: vi.fn().mockResolvedValue({
            products: [],
            categories: [],
            brands: [],
        }),
    },
}));

const { SearchBar } = await import('../SearchBar');

/**
 * What the header's search box searches within.
 *
 * It used to be the category tree, which the header already draws twice over:
 * the mega menu directly below was fed the very same prop, and typing returns
 * matching categories as suggestions anyway. A third copy, flattened into a
 * 116px box holding thirteen hundred names, was the least useful of the three
 * and the only one too narrow to show a name long enough to read.
 *
 * It scopes by availability now, which is the thing browsing cannot do: the
 * whole catalogue, or only what can be shipped today, or only what is cut in
 * price. Both are filters the listing already understands.
 */
describe('the header search scope', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        currentUrl = '/';
    });

    const open = async (user) =>
        user.click(screen.getByRole('combobox', { name: /search within/i }));

    it('offers the whole catalogue, what is in stock, and what is on offer', async () => {
        const user = userEvent.setup();
        render(<SearchBar />);
        await open(user);

        expect(
            screen.getAllByRole('option').map((o) => o.textContent.trim()),
        ).toEqual(['All Tech', 'In Stock', 'On Offer']);
    });

    /* The whole point: three short labels, where a category name never fit. */
    it('starts on the whole catalogue', () => {
        render(<SearchBar />);

        expect(
            screen.getByRole('combobox', { name: /search within/i }),
        ).toHaveTextContent('All Tech');
    });

    // ── where a choice takes you ─────────────────────────────────────

    it('narrows to what is in stock, with nothing typed', async () => {
        const user = userEvent.setup();
        render(<SearchBar />);

        await open(user);
        await user.click(screen.getByRole('option', { name: 'In Stock' }));

        expect(visit).toHaveBeenCalledWith('/shop?in_stock=1');
    });

    it('narrows to what is on offer', async () => {
        const user = userEvent.setup();
        render(<SearchBar />);

        await open(user);
        await user.click(screen.getByRole('option', { name: 'On Offer' }));

        expect(visit).toHaveBeenCalledWith('/shop?on_sale=1');
    });

    /* Narrowing mid-search runs that search inside the narrower set. */
    it('takes a half-typed term along rather than dropping it', async () => {
        const user = userEvent.setup();
        render(<SearchBar />);

        await user.type(
            screen.getByPlaceholderText(/search by product/i),
            'rtx 4090',
        );

        await open(user);
        await user.click(screen.getByRole('option', { name: 'In Stock' }));

        expect(visit).toHaveBeenCalledWith('/shop?search=rtx+4090&in_stock=1');
    });

    it('goes back to the whole catalogue when the narrowing is lifted', async () => {
        const user = userEvent.setup();
        currentUrl = '/products?in_stock=1';
        render(<SearchBar />);

        await open(user);
        await user.click(screen.getByRole('option', { name: 'All Tech' }));

        expect(visit).toHaveBeenCalledWith('/shop');
    });

    // ── following the page ───────────────────────────────────────────

    /*
     * Ticking "In Stock" in the filter sidebar and then searching used to
     * throw the narrowing away, because the box did not know about it.
     */
    it('follows a narrowing the page already carries', () => {
        currentUrl = '/products?in_stock=1';
        render(<SearchBar />);

        expect(
            screen.getByRole('combobox', { name: /search within/i }),
        ).toHaveTextContent('In Stock');
    });

    it('follows an offers listing too', () => {
        currentUrl = '/products?on_sale=1&page=2';
        render(<SearchBar />);

        expect(
            screen.getByRole('combobox', { name: /search within/i }),
        ).toHaveTextContent('On Offer');
    });

    /*
     * Both roots, because the listing answers at both: shop.index and
     * products.index render the same screen. Recognising only one meant
     * ticking "In Stock" on the other left the box still saying "All Tech".
     */
    it.each(['/shop', '/products'])(
        'follows a narrowing carried on %s',
        (root) => {
            currentUrl = `${root}?in_stock=1`;
            render(<SearchBar />);

            expect(
                screen.getByRole('combobox', { name: /search within/i }),
            ).toHaveTextContent('In Stock');
        },
    );

    /* Elsewhere the choice is the shopper's and nothing should undo it. */
    it('leaves the choice alone away from a listing', () => {
        currentUrl = '/cart';
        render(<SearchBar />);

        expect(
            screen.getByRole('combobox', { name: /search within/i }),
        ).toHaveTextContent('All Tech');
    });
});
