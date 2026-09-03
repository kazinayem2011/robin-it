import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const visit = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        visit: (...args) => visit(...args),
    },
    usePage: () => ({ url: '/' }),
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
 * The header's category dropdown.
 *
 * It offered six names written down in siteConfig, and four of them —
 * `components`, `laptops`, `monitors`, `gaming` — matched no category in the
 * shop: the real slugs are `component`, `laptop` and `monitor`, and nothing is
 * called gaming. It then sent the choice as `?category_slug=`, which the
 * listing does not read as a category at all — it takes its category from the
 * route, and an unrecognised parameter becomes a shelf-attribute filter. So
 * every option but "All Tech" asked for products with a spec called
 * "category_slug" and returned nothing.
 */
const MENU = [
    { id: 1, name: 'Desktop', slug: 'desktop' },
    { id: 2, name: 'Laptop', slug: 'laptop' },
    { id: 3, name: 'Component', slug: 'component' },
];

const search = async (user, term) => {
    const box =
        screen.getByRole('textbox', { name: '' }) ||
        document.querySelector('.search-text-input');

    await user.type(box, `${term}{Enter}`);
};

describe('the header search category', () => {
    beforeEach(() => visit.mockClear());

    it('offers the shop’s own categories, not a list kept by hand', async () => {
        const user = userEvent.setup();

        render(<SearchBar categories={MENU} />);
        await user.click(
            screen.getByRole('combobox', { name: /search within/i }),
        );

        expect(
            screen.getAllByRole('option').map((o) => o.textContent.trim()),
        ).toEqual(['All Tech', 'Desktop', 'Laptop', 'Component']);
    });

    it('still offers everything when the menu has not arrived yet', async () => {
        const user = userEvent.setup();

        render(<SearchBar />);
        await user.click(
            screen.getByRole('combobox', { name: /search within/i }),
        );

        expect(screen.getAllByRole('option')).toHaveLength(1);
        expect(screen.getByRole('option').textContent.trim()).toBe('All Tech');
    });

    it('searches the whole shop when no category is chosen', async () => {
        const user = userEvent.setup();

        render(<SearchBar categories={MENU} />);
        await search(user, 'corsair');

        expect(visit).toHaveBeenCalledWith('/shop?search=corsair');
    });

    /*
     * The category is the route. As a query parameter it was swept into the
     * listing's shelf filters and matched nothing at all.
     */
    it('sends the category as the route it is', async () => {
        const user = userEvent.setup();

        render(<SearchBar categories={MENU} />);

        await user.click(
            screen.getByRole('combobox', { name: /search within/i }),
        );
        await user.click(screen.getByRole('option', { name: 'Component' }));
        await search(user, 'corsair');

        expect(visit).toHaveBeenCalledWith('/shop/component?search=corsair');
        expect(visit.mock.calls[0][0]).not.toContain('category_slug');
    });

    it('does not navigate on an empty search of everything', async () => {
        const user = userEvent.setup();

        render(<SearchBar categories={MENU} />);
        await search(user, '   ');

        expect(visit).not.toHaveBeenCalled();
    });

    /*
     * Choosing used to only set a variable. With an empty search box, picking
     * "Laptop" did nothing at all — and neither did Enter or the button after
     * it, because submitting was guarded on there being a term. The shopper
     * had made a choice and the shop ignored it.
     */
    describe('choosing one', () => {
        const choose = async (user, name) => {
            await user.click(
                screen.getByRole('combobox', { name: /search within/i }),
            );
            await user.click(screen.getByRole('option', { name }));
        };

        it('goes to that category, with nothing typed', async () => {
            const user = userEvent.setup();

            render(<SearchBar categories={MENU} />);
            await choose(user, 'Laptop');

            expect(visit).toHaveBeenCalledWith('/shop/laptop');
        });

        it('takes a half-typed term along rather than dropping it', async () => {
            const user = userEvent.setup();

            render(<SearchBar categories={MENU} />);
            await user.type(
                document.querySelector('.search-text-input'),
                'corsair',
            );
            await choose(user, 'Component');

            expect(visit).toHaveBeenCalledWith(
                '/shop/component?search=corsair',
            );
        });

        it('widens back out to the whole shop', async () => {
            const user = userEvent.setup();

            render(<SearchBar categories={MENU} />);
            await choose(user, 'Laptop');
            visit.mockClear();
            await choose(user, 'All Tech');

            expect(visit).toHaveBeenCalledWith('/shop');
        });

        /* Re-picking what is already chosen is not a navigation. */
        it('stays put when the choice has not changed', async () => {
            const user = userEvent.setup();

            render(<SearchBar categories={MENU} />);
            await choose(user, 'All Tech');

            expect(visit).not.toHaveBeenCalled();
        });
    });
});
