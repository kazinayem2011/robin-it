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
 * Nothing may clip the category typeahead's results.
 *
 * It went wrong twice, the same way at two different heights. First the panel
 * itself was given `overflow-y: auto`, which looks like tidiness and cut the
 * list off — "Also list under" sits low on the Basics panel, so typing into it
 * appeared to do nothing at all. Removing that fixed the panel and not the
 * cause: the modal body around it scrolls too, and has to, so an absolutely
 * positioned list was still clipped by the dialog instead of by the panel.
 *
 * The list is fixed to the viewport now, measured from the field, which is
 * what actually settles it — overflow does not clip fixed descendants. The
 * panel assertions below are kept because a scrolling panel would still be
 * wrong for other reasons, but the position is the load-bearing one.
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
     * The one that actually keeps the list visible. An overflow ancestor
     * cannot clip a fixed descendant, so this holds however deeply the picker
     * is nested and whatever scrolls around it — which absolute never did.
     */
    it('fixes the list to the viewport so no ancestor can clip it', () => {
        expect(
            declaration(ruleFor('.category-picker-results {'), 'position'),
        ).toBe('fixed');
    });

    /*
     * Fixed coordinates come from measuring the field, so the component has to
     * be the thing placing it. A stylesheet top/left would pin every picker in
     * the shop to the same corner of the screen.
     */
    it('leaves the coordinates to the component', () => {
        const rule = ruleFor('.category-picker-results {');

        expect(declaration(rule, 'top')).toBeNull();
        expect(declaration(rule, 'left')).toBeNull();
    });
});
