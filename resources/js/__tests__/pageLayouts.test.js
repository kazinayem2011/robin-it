import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { resolve, join } from 'node:path';

/*
 * Every page used to render <MainLayout> inside its own tree. Inertia treats
 * that as part of the page, so the whole shell was torn down and rebuilt on
 * every navigation — and the header refetched the mega menu, the site settings
 * and the cart count each time.
 *
 * As a persistent layout (`Page.layout = mainLayout`) it mounts once and
 * survives page changes. These guard the arrangement: a new page that wraps the
 * shell by hand would silently bring the old behaviour back.
 */
const PAGES = resolve(process.cwd(), 'resources/js/Pages');

const walk = (dir) =>
    readdirSync(dir).flatMap((entry) => {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) {
            return entry === '__tests__' ? [] : walk(full);
        }
        return full.endsWith('.jsx') ? [full] : [];
    });

const rel = (p) => p.slice(resolve(process.cwd()).length + 1);
const read = (p) => readFileSync(p, 'utf8');

describe('page layouts', () => {
    it('no page renders the shell inside its own tree', () => {
        const offenders = walk(PAGES)
            .filter((f) => read(f).includes('<MainLayout'))
            .map(rel);

        expect(offenders).toEqual([]);
    });

    it('every page that imports the shell declares it as a persistent layout', () => {
        const offenders = walk(PAGES)
            .filter((f) => {
                const src = read(f);
                return (
                    src.includes('mainLayout') &&
                    !/\.layout\s*=\s*mainLayout/.test(src)
                );
            })
            .map(rel);

        expect(offenders).toEqual([]);
    });

    it('the shell is declared on a good number of pages', () => {
        const declaring = walk(PAGES).filter((f) =>
            /\.layout\s*=\s*mainLayout/.test(read(f)),
        );

        // Storefront + account. Admin pages use AdminLayout instead.
        expect(declaring.length).toBeGreaterThanOrEqual(20);
    });
});

describe('component imports', () => {
    /*
     * resources/js/Components/index.js re-exported every component, so any page
     * importing one thing from it pulled a single 126 kB shared chunk — the
     * union of everything any page used — onto first load. Components are
     * imported by path now.
     */
    it('the component barrel is not reintroduced', () => {
        expect(
            existsSync(
                resolve(process.cwd(), 'resources/js/Components/index.js'),
            ),
        ).toBe(false);
    });

    it('nothing imports a directory-level Components barrel', () => {
        const offenders = [
            ...walk(PAGES),
            ...walk(resolve(process.cwd(), 'resources/js/Components')),
        ]
            .filter((f) =>
                /from\s+'(?:@\/|(?:\.\.\/)+)Components';/.test(read(f)),
            )
            .map(rel);

        expect(offenders).toEqual([]);
    });
});
