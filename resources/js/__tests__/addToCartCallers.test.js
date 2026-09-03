import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, join } from 'node:path';

/*
 * Adding to the cart is one decision, made in one place.
 *
 * The server is asked for a product and an option, and refuses a product
 * alone — so every caller has to know to send the option, and every caller
 * has to surface the refusal when it cannot. Four places had written that out
 * separately and three had it wrong: the cards, quick view and the compare
 * page all posted a product with no option and reported "failed to add" with
 * the reason discarded.
 *
 * useAddToCart holds it now. This is the guard against a fifth copy.
 */
const SRC = resolve(process.cwd(), 'resources/js');

/*
 * The ones that legitimately call the service directly, and why:
 *
 *   useAddToCart       is the shared decision itself.
 *   VariantPickerModal has an option in hand — it is what asked for one.
 *   QuickViewModal     carries a quantity, and hands option products to the
 *                      picker before it ever posts.
 *   Products/Show      is the page with the option selector on it; the chosen
 *                      one goes with every add and every Buy Now.
 *   PcBuilder          adds a whole build in a loop, where raising a picker
 *                      part-way through would abandon the rest of the rig; it
 *                      reports each failure by name instead.
 */
const ALLOWED = [
    'hooks/useAddToCart.js',
    'Components/VariantPickerModal.jsx',
    'Components/QuickViewModal.jsx',
    'Pages/Products/Show.jsx',
    'Pages/PcBuilder/Index.jsx',
];

const walk = (dir) =>
    readdirSync(dir).flatMap((entry) => {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) {
            return entry === '__tests__' || entry === 'node_modules'
                ? []
                : walk(full);
        }
        return /\.(js|jsx)$/.test(full) ? [full] : [];
    });

describe('adding to the cart', () => {
    it('is not written out again anywhere new', () => {
        const offenders = walk(SRC)
            .filter((f) =>
                /cartService\.addToCart\(/.test(readFileSync(f, 'utf8')),
            )
            .map((f) => f.slice(SRC.length + 1))
            .filter((f) => !ALLOWED.includes(f));

        expect(offenders).toEqual([]);
    });

    /* And the ones allowed to are still the ones listed, so a file that stops
       calling it does not leave a stale exemption behind for a new one to
       slip through under. */
    it('has no stale exemptions', () => {
        const calling = walk(SRC)
            .filter((f) =>
                /cartService\.addToCart\(/.test(readFileSync(f, 'utf8')),
            )
            .map((f) => f.slice(SRC.length + 1));

        for (const allowed of ALLOWED) {
            expect(calling, `${allowed} no longer calls it`).toContain(allowed);
        }
    });
});
