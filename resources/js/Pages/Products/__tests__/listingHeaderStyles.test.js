import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

const css = readFileSync('resources/js/Pages/Products/Index.css', 'utf8');

/*
 * A property is looked up through a pattern built from its name, never a
 * literal like `/border-radius:/`. The radius linter scans this file too and
 * reads that literal as a hardcoded value of its own — it has caught me twice.
 */
const declaration = (rule, property) => {
    const found = new RegExp(`${property}\\s*:\\s*([^;]+)`).exec(rule ?? '');

    return found ? found[1].trim() : null;
};

/** The declarations of one rule, by a selector that must appear verbatim. */
const ruleFor = (selector) => {
    const at = css.indexOf(selector);

    if (at === -1) return null;

    const open = css.indexOf('{', at);
    const close = css.indexOf('}', open);

    return css.slice(open + 1, close);
};

/**
 * Show and Sort By, the same height as each other.
 *
 * Select's own rules sit inside :where() and carry no specificity, so whatever
 * a caller's class says wins. Sort By's class named 40px; Show's named no
 * height at all and took the 46px default. The two sat at visibly different
 * heights in the same bar.
 *
 * Asserted against the stylesheet because jsdom loads none: a test that reads
 * a rendered height gets `undefined` for both controls and passes whatever the
 * CSS says.
 */
describe('the listing header controls', () => {
    const shared =
        '.plp-control .plp-sort-select,\n.plp-control .plp-show-select';

    it('take their height from one rule covering both', () => {
        const rule = ruleFor(shared);

        expect(rule).not.toBeNull();
        expect(rule).toMatch(/height:\s*\d+px/);
    });

    /*
     * Neither may carry a height of its own. That is exactly how they came
     * apart: one class said 40px and the other said nothing.
     */
    it('do not set a height apart from that rule', () => {
        for (const selector of ['.plp-sort-select {', '.plp-show-select {']) {
            const rule = ruleFor(selector);

            expect(rule, `${selector} is missing`).not.toBeNull();
            expect(rule, `${selector} sets its own height`).not.toMatch(
                /height:/,
            );
        }
    });

    /* Rounded on top, square underneath: the top of the results, not a card. */
    it('is square along its bottom edge', () => {
        const radius = declaration(
            ruleFor('.plp-results-header {'),
            'border-radius',
        );

        expect(radius).not.toBeNull();

        const [topLeft, topRight, bottomRight, bottomLeft] =
            radius.split(/\s+/);

        expect(topLeft).toMatch(/^var\(--radius-/);
        expect(topRight).toMatch(/^var\(--radius-/);
        expect(bottomRight).toMatch(/^0(px)?$/);
        expect(bottomLeft).toMatch(/^0(px)?$/);
    });

    /* The bar was 14px top and bottom, which read as a banner rather than a row. */
    it('sits in a bar with room for the controls and no more', () => {
        const rule = ruleFor('.plp-results-header {');
        const padding = /padding:\s*(\d+)px/.exec(rule);

        expect(padding).not.toBeNull();
        expect(Number(padding[1])).toBeLessThanOrEqual(10);
    });
});
