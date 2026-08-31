import { describe, it, expect } from 'vitest';
import {
    paginationWindow,
    pageUrlFactory,
    readLinks,
    ELLIPSIS,
} from '../paginationWindow';

describe('paginationWindow', () => {
    it('shows every page while they all fit', () => {
        expect(paginationWindow(1, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
    });

    /* The case from the admin stock table: 51 pages drawn as thirteen buttons. */
    it('caps a long paginator at seven slots', () => {
        expect(paginationWindow(1, 51)).toEqual([1, 2, 3, 4, 5, ELLIPSIS, 51]);
    });

    it('keeps the current page between its neighbours in the middle', () => {
        expect(paginationWindow(25, 51)).toEqual([
            1,
            ELLIPSIS,
            24,
            25,
            26,
            ELLIPSIS,
            51,
        ]);
    });

    it('runs out to the end rather than leaving a gap of one', () => {
        expect(paginationWindow(50, 51)).toEqual([
            1,
            ELLIPSIS,
            47,
            48,
            49,
            50,
            51,
        ]);
    });

    it('never draws more than nine things', () => {
        for (let page = 1; page <= 200; page++) {
            expect(paginationWindow(page, 200).length).toBeLessThanOrEqual(9);
        }
    });

    it('has nothing to draw for a single page', () => {
        expect(paginationWindow(1, 1)).toEqual([1]);
        expect(paginationWindow(1, 0)).toEqual([]);
    });

    it('clamps a page number outside the range', () => {
        expect(paginationWindow(999, 10)).toEqual(paginationWindow(10, 10));
        expect(paginationWindow(0, 10)).toEqual(paginationWindow(1, 10));
    });
});

describe('pageUrlFactory', () => {
    /*
     * The window can now name pages Laravel left out of its own link list, so
     * the URL has to be built rather than looked up.
     */
    it('builds a URL for a page the server never linked', () => {
        const urlFor = pageUrlFactory([
            { url: null, label: '&laquo; Previous', active: false },
            {
                url: 'https://shop.test/admin/stock?page=1',
                label: '1',
                active: true,
            },
            {
                url: 'https://shop.test/admin/stock?page=2',
                label: '2',
                active: false,
            },
        ]);

        expect(urlFor(37)).toBe('https://shop.test/admin/stock?page=37');
    });

    it('keeps the other query parameters', () => {
        const urlFor = pageUrlFactory([
            { url: '/admin/stock?search=ssd&page=2', label: '2', active: true },
        ]);

        expect(urlFor(9)).toBe('/admin/stock?search=ssd&page=9');
    });

    it('returns nothing when there is no URL to copy', () => {
        expect(pageUrlFactory([])(2)).toBeNull();
    });
});

describe('readLinks', () => {
    it('reads the active page and the last page', () => {
        const links = [
            { url: '/x?page=2', label: '&laquo; Previous', active: false },
            { url: '/x?page=1', label: '1', active: false },
            { url: '/x?page=3', label: '3', active: true },
            { url: null, label: '...', active: false },
            { url: '/x?page=51', label: '51', active: false },
        ];

        expect(readLinks(links)).toEqual({ currentPage: 3, totalPages: 51 });
    });

    it('ignores the ellipsis rows Laravel inserts', () => {
        const { totalPages } = readLinks([
            { url: null, label: '...', active: false },
            { url: '/x?page=4', label: '4', active: true },
        ]);

        expect(totalPages).toBe(4);
    });
});
