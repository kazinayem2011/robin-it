import { describe, it, expect } from 'vitest';
import { formatBdt } from '../formatters';

describe('formatBdt', () => {
    it('formats in the Indian grouping the shop uses', () => {
        expect(formatBdt(81500)).toBe('৳81,500');
        expect(formatBdt(265000)).toBe('৳2,65,000');
    });

    /*
     * The minus belongs before the symbol. It used to sit between the symbol
     * and the digits — "৳-1,16,600" — which reads as a typo, and negatives are
     * ordinary on a profit and loss statement rather than a rare path.
     */
    it('puts a minus before the currency symbol, not after it', () => {
        expect(formatBdt(-116600)).toBe('-৳1,16,600');
        expect(formatBdt(-1)).toBe('-৳1');
    });

    it('treats nothing as zero rather than NaN', () => {
        for (const empty of [null, undefined, '', 'not a number']) {
            expect(formatBdt(empty)).toBe('৳0');
        }
    });

    it('accepts a numeric string', () => {
        expect(formatBdt('81500')).toBe('৳81,500');
        expect(formatBdt('-500')).toBe('-৳500');
    });

    it('rounds to whole taka', () => {
        expect(formatBdt(99.4)).toBe('৳99');
        expect(formatBdt(99.6)).toBe('৳100');
    });
});
