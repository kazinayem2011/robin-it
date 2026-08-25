import { describe, it, expect } from 'vitest';
import { normaliseParams } from '../axiosInstance';

/*
 * axios stringifies a JS `true` as "true". Laravel's `boolean` rule compares
 * strictly against [true, false, 0, 1, '0', '1'], so "true" is rejected —
 * ticking "In stock only" in the shop 422'd and blanked the grid.
 */
describe('normaliseParams', () => {
    it('sends booleans as the 1 and 0 the backend validates', () => {
        expect(normaliseParams({ in_stock: true, on_sale: false })).toEqual({
            in_stock: 1,
            on_sale: 0,
        });
    });

    it('drops values that carry no filter', () => {
        expect(
            normaliseParams({
                page: 2,
                min_price: undefined,
                max_price: '',
                brand_ids: null,
            }),
        ).toEqual({ page: 2 });
    });

    it('leaves arrays alone for axios to serialise', () => {
        expect(normaliseParams({ brand_ids: [2, 4] })).toEqual({
            brand_ids: [2, 4],
        });
    });

    it('keeps a zero, which is a real value', () => {
        expect(normaliseParams({ min_price: 0 })).toEqual({ min_price: 0 });
    });

    it('handles an empty object', () => {
        expect(normaliseParams({})).toEqual({});
    });
});
