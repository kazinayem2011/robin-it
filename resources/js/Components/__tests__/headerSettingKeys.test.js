import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/*
 * The header read siteSettings.announcement_ticker while the admin form writes
 * announcement_text. Nothing has ever stored that key, so the ticker always
 * fell through to its hardcoded default and editing it under Settings changed
 * nothing on the site — silently, because a missing key is indistinguishable
 * from an empty one.
 *
 * Resolved from the working directory rather than import.meta.url, which under
 * the jsdom environment is an http:// URL that node:url will not convert.
 */
const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8');

/** Keys the header expects the settings payload to carry. */
const keysHeaderReads = () => {
    const source = read('resources/js/Components/Header.jsx');

    return new Set(
        [...source.matchAll(/siteSettings\.([a-z0-9_]+)/g)].map((m) => m[1]),
    );
};

/** Keys the admin form can actually write. */
const keysAdminWrites = () => {
    const source = read('resources/js/Pages/Admin/Settings.jsx');
    const start = source.indexOf('initialValues: {');
    const end = source.indexOf('validationSchema', start);

    return new Set(
        [...source.slice(start, end).matchAll(/^\s{12}([a-z0-9_]+):/gm)].map(
            (m) => m[1],
        ),
    );
};

describe('header settings keys', () => {
    it('only reads keys the settings form writes', () => {
        const written = keysAdminWrites();
        const orphaned = [...keysHeaderReads()].filter((k) => !written.has(k));

        expect(orphaned).toEqual([]);
    });

    it('reads the announcement under the key the form saves', () => {
        const reads = keysHeaderReads();

        expect(reads).toContain('announcement_text');
        expect(reads).not.toContain('announcement_ticker');
    });

    /* The settings screen has an on/off switch; it has to be consulted. */
    it('consults the announcement on/off switch', () => {
        expect(keysHeaderReads()).toContain('announcement_active');
    });

    /* Guards the guard: a broken extractor would make the first test vacuous. */
    it('finds keys in both files', () => {
        expect(keysHeaderReads().size).toBeGreaterThan(3);
        expect(keysAdminWrites().size).toBeGreaterThan(15);
    });
});
