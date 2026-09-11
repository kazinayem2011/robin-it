import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

const read = (path) => readFileSync(path, 'utf8');

/**
 * One icon for comparing, wherever it is offered.
 *
 * The dock and the comparison page used the scales; the product card used
 * RefreshCw — a reload arrow — and the detail page used SquarePlus. So the
 * same action wore three different icons, one of which already means "reload"
 * everywhere else in this shop, including on the image cropper's Reset button
 * and beside "7-Day Easy Replacement" on the home page.
 *
 * Read from source rather than rendered: what is being pinned is the choice of
 * icon, and every one of these renders an <svg> with no name of its own.
 */
describe('the compare affordance', () => {
    const places = [
        ['the product card', 'resources/js/Components/ProductCard.jsx'],
        ['the detail page', 'resources/js/Pages/Products/Show.jsx'],
        ['the quick dock', 'resources/js/Components/QuickDock.jsx'],
        ['the comparison page', 'resources/js/Pages/Compare/Index.jsx'],
    ];

    it.each(places)('uses the scales on %s', (_where, path) => {
        const source = read(path);

        expect(source).toMatch(/\bScale\b/);
    });

    /*
     * The card offered it twice, in two layouts, and both were the reload
     * arrow. Neither may quietly go back.
     */
    it('leaves no reload arrow on the product card', () => {
        expect(read('resources/js/Components/ProductCard.jsx')).not.toMatch(
            /RefreshCw/,
        );
    });

    it('leaves no plus box on the detail page', () => {
        expect(read('resources/js/Pages/Products/Show.jsx')).not.toMatch(
            /SquarePlus/,
        );
    });
});
