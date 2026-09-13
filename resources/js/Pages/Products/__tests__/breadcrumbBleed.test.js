import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

const read = (p) => readFileSync(p, 'utf8');

const rule = (css, selector) => {
    const at = css.indexOf(selector);

    if (at === -1) return null;

    const open = css.indexOf('{', at);

    return css.slice(open + 1, css.indexOf('}', open));
};

/**
 * One page's stylesheet reaching another page's markup.
 *
 * `.breadcrumbs` is declared by the product page, with a margin, a padding and
 * a border-bottom under its trail. The listing uses the same class name and
 * refines it — but page stylesheets are bundled together and stay loaded once
 * visited, so from the first product page onward the listing drew a line under
 * its breadcrumb that it had never drawn on a fresh session. The margin and
 * the padding were already being undone; the border was missed, which is why
 * it only showed up after navigating.
 *
 * Asserted against the stylesheets because jsdom applies no CSS at all: what
 * is being checked is that one rule answers the other.
 */
describe('the listing breadcrumb', () => {
    const listing = read('resources/js/Pages/Products/Index.css');
    const detail = read('resources/js/Pages/Products/Show.css');

    it('is still styled by the detail page, which is why this matters', () => {
        expect(rule(detail, '.breadcrumbs {')).toMatch(/border-bottom/);
    });

    it('undoes every part of that rule, not just the spacing', () => {
        const own = rule(listing, '.breadcrumbs.plp-breadcrumbs-spacer {');

        expect(own).not.toBeNull();

        for (const property of [
            'margin-bottom',
            'padding-bottom',
            'border-bottom',
        ]) {
            expect(own).toMatch(new RegExp(`${property}\\s*:`));
        }
    });

    /*
     * The chip sits under the breadcrumb rather than beside it. It carried a
     * left margin from when the two shared a line, and swapping that for a top
     * margin only moved the problem — a child deciding its own spacing is what
     * made both wrong. The stack declares one gap for the pair instead.
     */
    it('never indents the chip from the trail it sits under', () => {
        expect(rule(listing, '.plp-search-chip {')).not.toMatch(/margin-left/);
    });

    /*
     * The chip is inline-flex on its own line, and the space above it is that
     * line's. Making the wrapper a flex column takes the line boxes away and
     * leaves only whatever gap is declared, which closed the pair up.
     */
    it('leaves the trail and the chip in normal block flow', () => {
        const stack = rule(listing, '.plp-header-stack {');

        expect(stack).not.toMatch(/flex-direction:\s*column/);
        expect(stack).not.toMatch(/gap:/);
    });
});
