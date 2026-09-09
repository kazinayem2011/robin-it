import { describe, it, expect } from 'vitest';
import { moveItem, reorderSiblings, indexOnShelf } from '../reorderTree';

const tree = () => [
    {
        id: 1,
        name: 'Component',
        children: [{ id: 10 }, { id: 11 }, { id: 12 }],
    },
    { id: 2, name: 'Laptop', children: [{ id: 20 }, { id: 21 }] },
    { id: 3, name: 'Monitor', children: [] },
];

const ids = (list) => list.map((row) => row.id);

describe('moveItem', () => {
    const list = [{ id: 'a' }, { id: 'b' }, { id: 'c' }, { id: 'd' }];

    it('carries a row down the list', () => {
        expect(ids(moveItem(list, 0, 2))).toEqual(['b', 'c', 'a', 'd']);
    });

    it('carries a row up the list', () => {
        expect(ids(moveItem(list, 3, 1))).toEqual(['a', 'd', 'b', 'c']);
    });

    /* Dropped below the last row is how somebody says "put it last". */
    it('clamps a drop past the end rather than losing the row', () => {
        expect(ids(moveItem(list, 0, 99))).toEqual(['b', 'c', 'd', 'a']);
    });

    it('clamps a drop above the first row', () => {
        expect(ids(moveItem(list, 2, -5))).toEqual(['c', 'a', 'b', 'd']);
    });

    /*
     * Returned unchanged, by identity. A drag crosses the row it started on
     * on the way back, and a new array each time re-renders the whole shelf
     * mid-drag.
     */
    it('returns the same array when nothing moves', () => {
        expect(moveItem(list, 1, 1)).toBe(list);
        expect(moveItem(list, 7, 0)).toBe(list);
        expect(moveItem([], 0, 1)).toEqual([]);
    });

    it('leaves the original alone', () => {
        const before = [...list];
        moveItem(list, 0, 3);
        expect(list).toEqual(before);
    });
});

describe('reorderSiblings', () => {
    it('reorders the roots when there is no parent', () => {
        expect(ids(reorderSiblings(tree(), null, 2, 0))).toEqual([3, 1, 2]);
    });

    it('reorders one parent’s children', () => {
        const next = reorderSiblings(tree(), 1, 2, 0);
        expect(ids(next[0].children)).toEqual([12, 10, 11]);
    });

    /* Positions are per parent — the same rule the server enforces. */
    it('leaves every other shelf untouched', () => {
        const before = tree();
        const next = reorderSiblings(before, 1, 0, 2);

        expect(ids(next[1].children)).toEqual([20, 21]);
        expect(next[1]).toBe(before[1]);
        expect(next[2]).toBe(before[2]);
    });

    it('copies the parent it rewrites rather than mutating it', () => {
        const before = tree();
        const next = reorderSiblings(before, 1, 0, 2);

        expect(next[0]).not.toBe(before[0]);
        expect(ids(before[0].children)).toEqual([10, 11, 12]);
    });

    it('survives a parent with no children at all', () => {
        expect(() => reorderSiblings(tree(), 3, 0, 1)).not.toThrow();
    });

    it('ignores a parent that is not there', () => {
        const before = tree();
        expect(reorderSiblings(before, 999, 0, 1)).toEqual(before);
    });
});

describe('indexOnShelf', () => {
    it('finds a root among the roots', () => {
        expect(indexOnShelf(tree(), null, 2)).toBe(1);
    });

    it('finds a child among its siblings', () => {
        expect(indexOnShelf(tree(), 1, 12)).toBe(2);
    });

    /*
     * A row is only ever on its own shelf. Answering with an index from
     * somewhere else would move a different category.
     */
    it('does not find a child on another parent’s shelf', () => {
        expect(indexOnShelf(tree(), 2, 12)).toBe(-1);
        expect(indexOnShelf(tree(), null, 12)).toBe(-1);
    });
});
