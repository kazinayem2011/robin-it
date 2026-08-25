import { describe, it, expect } from 'vitest';
import { withBrand } from '../pageTitle';

describe('withBrand', () => {
    it('adds the brand to a page that does not carry it', () => {
        expect(withBrand('Custom PC Builder', 'Robins Computer')).toBe(
            'Custom PC Builder — Robins Computer',
        );
    });

    /* The bug this exists to prevent: the brand printed twice. */
    it('leaves a title that already ends with the brand alone', () => {
        expect(
            withBrand('Custom PC Builder — Robins Computer', 'Robins Computer'),
        ).toBe('Custom PC Builder — Robins Computer');
    });

    it('does not double the brand on the home page title', () => {
        expect(
            withBrand(
                'Robins Computer — The Store of Technology',
                'Robins Computer',
            ),
        ).toBe('Robins Computer — The Store of Technology');
    });

    it('falls back to the brand when the page has no title', () => {
        expect(withBrand('', 'Electronics Store')).toBe('Electronics Store');
        expect(withBrand(undefined, 'Electronics Store')).toBe(
            'Electronics Store',
        );
    });

    it('returns the title unchanged when there is no brand to add', () => {
        expect(withBrand('Suppliers', '')).toBe('Suppliers');
        expect(withBrand('Suppliers', null)).toBe('Suppliers');
    });

    it('never renders the string "undefined"', () => {
        expect(withBrand(undefined, undefined)).toBe('');
    });
});
