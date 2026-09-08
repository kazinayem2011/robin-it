import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const { Pagination } = await import('../Pagination');

/** Stand in for a viewport of the given width against a max-width query. */
const viewport = (width) => {
    window.matchMedia = vi.fn().mockImplementation((query) => {
        const limit = Number(
            /max-width:\s*(\d+)px/.exec(query)?.[1] ?? Infinity,
        );
        return {
            matches: width <= limit,
            media: query,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        };
    });
};

const pages = (n) => (page) =>
    Array.from({ length: n + 2 }, (_, i) => {
        if (i === 0)
            return {
                url: page > 1 ? `/x?page=${page - 1}` : null,
                label: '&laquo; Previous',
            };
        if (i === n + 1)
            return {
                url: page < n ? `/x?page=${page + 1}` : null,
                label: 'Next &raquo;',
            };
        return { url: `/x?page=${i}`, label: String(i), active: i === page };
    });

const shown = () =>
    [...document.querySelectorAll('.pagination-btns > *')]
        .map((el) => el.textContent.trim())
        .filter((t) => /^\d+$/.test(t));

/**
 * Nine controls do not fit across a phone.
 *
 * Seven page slots plus both arrows put the last page and the next arrow on a
 * second row under the other seven — on the 1,269-product list that is what
 * the footer looked like. The component asks for a narrower window below
 * 640px; nothing about the result says which window it got, so the count is
 * what has to be checked.
 */
describe('Pagination on a phone', () => {
    beforeEach(() => vi.clearAllMocks());

    it('draws five page slots on a phone', () => {
        viewport(390);
        render(<Pagination links={pages(64)(1)} />);

        // 1 2 3 … 64 — four numbers and a gap.
        expect(shown()).toEqual(['1', '2', '3', '64']);
    });

    it('draws seven on a desktop, as before', () => {
        viewport(1440);
        render(<Pagination links={pages(64)(1)} />);

        expect(shown()).toEqual(['1', '2', '3', '4', '5', '64']);
    });

    it('keeps the first and last page reachable from the middle of a long list', () => {
        viewport(390);
        render(<Pagination links={pages(64)(25)} />);

        // The jump to either end survives the narrower window.
        expect(shown()).toEqual(['1', '25', '64']);
        expect(screen.getByLabelText('Page 64')).toBeInTheDocument();
        expect(screen.getByLabelText('Previous page')).toBeInTheDocument();
        expect(screen.getByLabelText('Next page')).toBeInTheDocument();
    });
});
