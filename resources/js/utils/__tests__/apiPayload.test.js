import { describe, it, expect } from 'vitest';
import { listFrom, payloadFrom } from '../apiPayload';

/* The shapes the admin API actually returns. */
const envelope = (data) => ({
    error: false,
    code: 200,
    message: 'OK',
    data,
    meta: {},
});
const paginator = (rows) => ({
    current_page: 1,
    data: rows,
    total: rows.length,
});

describe('listFrom', () => {
    it('reads a plain list inside the envelope', () => {
        expect(listFrom(envelope([{ id: 1 }]))).toEqual([{ id: 1 }]);
    });

    /** The one that blanked the stock page. */
    it('reads a paginated list, which is wrapped twice', () => {
        expect(listFrom(envelope(paginator([{ id: 7 }])))).toEqual([{ id: 7 }]);
    });

    it('accepts a bare array, for a caller that already unwrapped', () => {
        expect(listFrom([{ id: 2 }])).toEqual([{ id: 2 }]);
    });

    it('is an empty list when there is nothing to find', () => {
        expect(listFrom(envelope(paginator([])))).toEqual([]);
        expect(listFrom(envelope(null))).toEqual([]);
        expect(listFrom(undefined)).toEqual([]);
        expect(listFrom({})).toEqual([]);
    });

    /** Never a paginator object where a list belongs — that is what threw. */
    it('never returns something without .map', () => {
        for (const input of [
            envelope(paginator([])),
            envelope({}),
            null,
            0,
            'x',
        ]) {
            expect(typeof listFrom(input).map).toBe('function');
        }
    });
});

describe('payloadFrom', () => {
    it('reads the object inside the envelope', () => {
        const body = { product: { id: 5 }, integrity: { matches: true } };

        expect(payloadFrom(envelope(body))).toEqual(body);
    });

    it('is an empty object when there is nothing', () => {
        expect(payloadFrom(null)).toEqual({});
        expect(payloadFrom(undefined)).toEqual({});
    });
});
