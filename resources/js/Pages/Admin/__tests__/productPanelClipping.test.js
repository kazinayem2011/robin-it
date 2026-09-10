import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

const css = readFileSync('resources/js/Layouts/AdminLayout.css', 'utf8');

const ruleFor = (selector) => {
    const at = css.indexOf(selector);

    if (at === -1) return null;

    const open = css.indexOf('{', at);

    return css.slice(open + 1, css.indexOf('}', open));
};

const declaration = (rule, property) => {
    const found = new RegExp(`${property}\\s*:\\s*([^;]+)`).exec(rule ?? '');

    return found ? found[1].trim() : null;
};

/**
 * The product panels must not clip what opens out of them.
 *
 * The category typeahead positions its results absolutely, so any ancestor
 * with an overflow other than visible cuts them off. "Also list under" sits
 * low on the Basics panel, so giving the panel `overflow-y: auto` — which
 * looks like tidiness — hid its results completely, and typing into the field
 * appeared to do nothing at all. The dialog around it already scrolls.
 *
 * Asserted against the stylesheet because jsdom has no layout: nothing
 * rendered in a test can tell you a box was clipped.
 */
describe('the product form panels', () => {
    const rule = () => ruleFor('.admin-product-tabpanel {');

    it('exists', () => {
        expect(rule()).not.toBeNull();
    });

    it('does not clip, so a typeahead can open past its edge', () => {
        const overflow = ['overflow', 'overflow-y', 'overflow-x']
            .map((property) => declaration(rule(), property))
            .filter(Boolean);

        for (const value of overflow) {
            expect(value).toBe('visible');
        }
    });

    /* A height cap would clip just as surely, whatever the overflow says. */
    it('sets no maximum height', () => {
        expect(declaration(rule(), 'max-height')).toBeNull();
    });

    /*
     * The results are absolute, which is the reason all of the above matters.
     * If that ever changes, this test is the thing that should be revisited.
     */
    it('is guarding an absolutely positioned list', () => {
        expect(
            declaration(ruleFor('.category-picker-results {'), 'position'),
        ).toBe('absolute');
    });
});
