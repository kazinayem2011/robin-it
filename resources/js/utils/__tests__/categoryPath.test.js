import { describe, it, expect } from 'vitest';
import { categoryPath, categoryFullName } from '../categoryPath';

/**
 * Naming a shelf so it can be told from the others with its name.
 *
 * The tree repeats names deliberately: four shelves are called Asus, one under
 * each parent, and several are called Accessories. Every screen that shows a
 * category therefore shows its ancestry — and each had grown its own copy of
 * this, written slightly differently, with two of them writing nothing at all.
 */
describe('categoryPath', () => {
    const asus = {
        name: 'Asus',
        parent: { name: 'Gaming Laptop', parent: { name: 'Laptop' } },
    };

    it('reads from the top down, nearest last', () => {
        expect(categoryPath(asus)).toBe('Laptop › Gaming Laptop');
    });

    it('gives the whole thing when asked, leaf included', () => {
        expect(categoryFullName(asus)).toBe('Laptop › Gaming Laptop › Asus');
    });

    it('stops at one ancestor when that is all there is', () => {
        expect(
            categoryPath({ name: 'All Laptop', parent: { name: 'Laptop' } }),
        ).toBe('Laptop');
    });

    /* A top-level shelf has no ancestry, and must not be given an empty one. */
    it('is empty at the top of the tree', () => {
        expect(categoryPath({ name: 'Laptop' })).toBe('');
        expect(categoryFullName({ name: 'Laptop' })).toBe('Laptop');
    });

    /*
     * The relation is only loaded where a screen needs it, so half a chain is
     * an ordinary thing to be handed rather than a fault.
     */
    it('skips a missing link rather than writing a gap', () => {
        expect(
            categoryPath({
                name: 'Asus',
                parent: { parent: { name: 'Laptop' } },
            }),
        ).toBe('Laptop');
    });

    it('says nothing about nothing', () => {
        expect(categoryPath(null)).toBe('');
        expect(categoryPath(undefined)).toBe('');
        expect(categoryFullName(null)).toBe('');
        expect(categoryFullName({})).toBe('');
    });
});
