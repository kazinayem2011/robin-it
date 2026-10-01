import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const visit = vi.fn();
let currentUrl = '/';
let settings = {};

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: (...args) => visit(...args) },
    usePage: () => ({ url: currentUrl, props: { site_settings: settings } }),
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
 * The header search's two switchable parts (Settings → Header & Ticker): the
 * "All Tech" dropdown, off unless switched on, and the "Hot" row, on unless
 * switched off.
 */
describe('the header search switches', () => {
    beforeEach(() => {
        visit.mockReset();
        currentUrl = '/';
        settings = {};
    });

    it('is one plain box by default, with the Hot row', () => {
        render(<SearchBar />);

        expect(screen.queryByLabelText('Search within')).toBeNull();
        expect(screen.getByText(/Hot:/)).toBeInTheDocument();
    });

    it('shows the dropdown when switched on, and hides Hot when switched off', () => {
        settings = { header_search_scope: '1', header_hot_searches: '0' };
        render(<SearchBar />);

        expect(screen.getByLabelText('Search within')).toBeInTheDocument();
        expect(screen.queryByText(/Hot:/)).toBeNull();
    });

    // Hidden, it cannot narrow a search behind the shopper's back.
    it('searches everything while the dropdown is hidden, even on a narrowed listing', async () => {
        currentUrl = '/shop?in_stock=1';
        const user = userEvent.setup();
        render(<SearchBar />);

        await user.type(
            screen.getByPlaceholderText(/Search by product/),
            'laptop{Enter}',
        );

        expect(visit).toHaveBeenCalledTimes(1);
        expect(visit.mock.calls[0][0]).toContain('laptop');
        expect(visit.mock.calls[0][0]).not.toContain('in_stock');
    });
});
