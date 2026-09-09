import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

/**
 * The loading skeleton has to be the shape of the page it stands in for.
 *
 * It drifted: the product page moved to a 1fr/2fr grid and a 4:3 gallery while
 * the skeleton stayed on two equal columns and a fixed 420px block. The whole
 * point of a skeleton over a spinner is that nothing moves when the content
 * lands, and a skeleton of the wrong shape does the opposite — it guarantees a
 * jump, and a wider one than no placeholder at all.
 *
 * The two grids live in different stylesheets, so nothing but a test holds
 * them together.
 */
describe('product detail skeleton shape', () => {
    const rule = (file, selector) => {
        const css = readFileSync(file, 'utf8');
        const at = css.indexOf(selector);

        expect(at, `${selector} not found in ${file}`).toBeGreaterThan(-1);

        return css
            .slice(at, css.indexOf('}', at))
            .replace(/\/\*[\s\S]*?\*\//g, '');
    };

    const columns = (declarations) => {
        const match = declarations.match(/grid-template-columns:\s*([^;]+);/);
        expect(match, 'no grid-template-columns').toBeTruthy();

        // minmax(0, 2fr) and 2fr are the same column for this purpose.
        return (match[1].match(/[\d.]+fr/g) || []).join(' ');
    };

    it('splits its columns the way the page does', () => {
        const page = columns(
            rule('resources/js/Pages/Products/Show.css', '.pdp-top {'),
        );
        const skeleton = columns(
            rule('resources/css/app.css', '.skeleton-pdp {'),
        );

        expect(skeleton).toBe(page);
    });

    it('leaves the same gap between them', () => {
        const gapOf = (declarations) =>
            declarations.match(/(?:^|\s)gap:\s*([^;]+);/)?.[1]?.trim();

        expect(gapOf(rule('resources/css/app.css', '.skeleton-pdp {'))).toBe(
            gapOf(rule('resources/js/Pages/Products/Show.css', '.pdp-top {')),
        );
    });

    /*
     * The gallery is the tallest thing on the page, so its shape is what
     * decides whether the column below it moves. A fixed height here against a
     * ratio there is the drift this caught.
     */
    it('stands the gallery in at the frame’s own ratio', () => {
        const frame = rule(
            'resources/js/Pages/Products/Show.css',
            '.main-image {',
        );
        expect(frame).toMatch(/aspect-ratio:\s*4\s*\/\s*3/);

        const skeleton = readFileSync(
            'resources/js/Components/Skeleton.jsx',
            'utf8',
        );
        const block = skeleton.slice(
            skeleton.indexOf('export const ProductDetailSkeleton'),
        );

        expect(block).toMatch(/aspectRatio:\s*'4 \/ 3'/);
        // A fixed height would override the ratio, the same way a max-height
        // once overrode the card's.
        expect(block).not.toMatch(/height="420px"/);
    });

    /* The chips wrap on the page, so they have to be able to wrap here. */
    it('lets the fact row wrap like the real one', () => {
        expect(rule('resources/css/app.css', '.skeleton-pdp-chips {')).toMatch(
            /flex-wrap:\s*wrap/,
        );
    });
});
