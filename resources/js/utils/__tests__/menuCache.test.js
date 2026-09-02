import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { readCachedMenu, writeCachedMenu } from '../menuCache';

/**
 * The category bar is fetched after hydration, so every full page load painted
 * an empty black bar and then filled it — ~0.9s locally, ~4.5s on the shared
 * host. Remembering the last tree removes the gap on every load but the first.
 *
 * Nothing read back is trusted: storage can be absent, stale or edited by hand,
 * and a header that throws is worse than one drawn a moment late.
 */
describe('menuCache', () => {
    const menu = [
        { name: 'Desktop', slug: 'desktop', children: [] },
        { name: 'Laptop', slug: 'laptop', children: [] },
    ];

    beforeEach(() => window.localStorage.clear());
    afterEach(() => vi.restoreAllMocks());

    it('gives nothing back before anything is stored', () => {
        expect(readCachedMenu()).toEqual([]);
    });

    it('returns what was written', () => {
        writeCachedMenu(menu);

        expect(readCachedMenu()).toEqual(menu);
    });

    it('forgets a tree older than its keep-alive', () => {
        writeCachedMenu(menu);

        // Seven hours later; the ceiling is six.
        vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 7 * 60 * 60 * 1000);

        expect(readCachedMenu()).toEqual([]);
    });

    it.each([
        ['not an array', '{"at":1,"tree":{"name":"x"}}'],
        ['an empty list', '{"at":1,"tree":[]}'],
        ['rows without a slug', '{"at":1,"tree":[{"name":"Desktop"}]}'],
        ['torn json', '{"at":1,"tree":['],
        ['nonsense', 'not json at all'],
    ])('discards %s rather than rendering it', (_label, raw) => {
        window.localStorage.setItem('robinit.megamenu.v1', raw);

        expect(readCachedMenu()).toEqual([]);
    });

    it('refuses to store something that is not a menu', () => {
        writeCachedMenu([{ name: 'Desktop' }]);

        expect(readCachedMenu()).toEqual([]);
    });

    /* A private window, or site data switched off. */
    it('survives storage that throws on read', () => {
        vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
            throw new Error('denied');
        });

        expect(() => readCachedMenu()).not.toThrow();
        expect(readCachedMenu()).toEqual([]);
    });

    it('survives storage that throws on write', () => {
        vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
            throw new Error('quota');
        });

        expect(() => writeCachedMenu(menu)).not.toThrow();
    });
});
