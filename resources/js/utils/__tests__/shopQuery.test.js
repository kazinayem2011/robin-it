import { describe, it, expect } from 'vitest';
import { parseShopQuery, buildShopSearch } from '../shopQuery';

describe('parseShopQuery', () => {
    it('defaults to page one, latest, no filters', () => {
        expect(parseShopQuery('')).toEqual({
            page: 1,
            sort: 'latest',
            filters: {},
        });
    });

    it('reads paging, sorting and every filter', () => {
        expect(
            parseShopQuery(
                '?page=3&sort=price_low_high&min_price=9800&max_price=45000&brand_ids=2,4&in_stock=1&on_sale=1',
            ),
        ).toEqual({
            page: 3,
            sort: 'price_low_high',
            filters: {
                min_price: 9800,
                max_price: 45000,
                brand_ids: [2, 4],
                in_stock: true,
                on_sale: true,
            },
        });
    });

    it('ignores a sort the listing does not offer', () => {
        expect(parseShopQuery('?sort=cheapest_ever').sort).toBe('latest');
    });

    it.each(['0', '-2', 'abc', ''])('falls back to page one for %o', (raw) => {
        expect(parseShopQuery(`?page=${raw}`).page).toBe(1);
    });

    it('drops brand ids that are not numbers', () => {
        expect(
            parseShopQuery('?brand_ids=2,,oops,4').filters.brand_ids,
        ).toEqual([2, 4]);
    });

    it('leaves brand_ids unset rather than empty when none survive', () => {
        expect(parseShopQuery('?brand_ids=oops').filters).toEqual({});
    });

    /* A checkbox that is off should not come back on. */
    it('treats anything but 1 as unchecked', () => {
        expect(parseShopQuery('?in_stock=0&on_sale=false').filters).toEqual({});
    });
});

describe('per-listing default sort', () => {
    /*
     * The offers page leads with the deepest discount. Its default must not
     * appear in the URL, or every visit would rewrite /offers to
     * /offers?sort=discount_high.
     */
    it('falls back to the listing default rather than latest', () => {
        expect(parseShopQuery('', 'discount_high').sort).toBe('discount_high');
    });

    it('still lets the URL override the listing default', () => {
        expect(
            parseShopQuery('?sort=price_low_high', 'discount_high').sort,
        ).toBe('price_low_high');
    });

    it('rejects an unknown sort back to the listing default', () => {
        expect(parseShopQuery('?sort=nonsense', 'discount_high').sort).toBe(
            'discount_high',
        );
    });

    it('omits the listing default from the querystring', () => {
        expect(
            buildShopSearch({
                sort: 'discount_high',
                defaultSort: 'discount_high',
            }),
        ).toBe('');
    });

    it('names a sort that differs from the listing default', () => {
        expect(
            buildShopSearch({ sort: 'latest', defaultSort: 'discount_high' }),
        ).toBe('?sort=latest');
    });

    it('round-trips against a non-default listing sort', () => {
        const state = { page: 2, sort: 'latest', filters: { on_sale: true } };
        const search = buildShopSearch({
            ...state,
            defaultSort: 'discount_high',
        });

        expect(parseShopQuery(search, 'discount_high')).toEqual(state);
    });

    it('accepts the discount sort the API validates', () => {
        expect(parseShopQuery('?sort=discount_high').sort).toBe(
            'discount_high',
        );
    });
});

describe('buildShopSearch', () => {
    it('is empty for an untouched listing', () => {
        expect(buildShopSearch({ page: 1, sort: 'latest', filters: {} })).toBe(
            '',
        );
    });

    it('omits page one and the default sort', () => {
        expect(
            buildShopSearch({
                page: 1,
                sort: 'latest',
                filters: { on_sale: true },
            }),
        ).toBe('?on_sale=1');
    });

    it('joins brand ids with commas', () => {
        expect(buildShopSearch({ filters: { brand_ids: [2, 4] } })).toBe(
            '?brand_ids=2%2C4',
        );
    });

    it('round-trips a fully specified listing', () => {
        const state = {
            page: 3,
            sort: 'price_low_high',
            filters: {
                min_price: 9800,
                max_price: 45000,
                brand_ids: [2, 4],
                in_stock: true,
                on_sale: true,
            },
        };

        expect(parseShopQuery(buildShopSearch(state))).toEqual(state);
    });

    it('survives the empty call', () => {
        expect(buildShopSearch()).toBe('');
    });
});
