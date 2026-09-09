import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

const css = readFileSync('resources/js/Pages/Products/Index.css', 'utf8');

const ruleFor = (selector) => {
    const at = css.indexOf(selector);

    if (at === -1) return null;

    const open = css.indexOf('{', at);

    return css.slice(open + 1, css.indexOf('}', open));
};

const declaration = (rule, property) => {
    const found = new RegExp(`(?:^|[;{\\s])${property}:\\s*([^;]+)`).exec(rule);

    return found ? found[1].trim() : null;
};

/**
 * The panel's left edge, and the shape of a checkbox.
 *
 * Asserted against the stylesheet because jsdom loads none — a test that
 * measures a rendered box gets nothing back and passes whatever the file says.
 */
describe('the filter panel', () => {
    /*
     * Every option carried 8px of horizontal padding, so each checkbox and
     * label stood 8px right of the section heading above it: a step in the
     * left edge at every group.
     */
    it('lines an option up with the heading it sits under', () => {
        const padding = declaration(ruleFor('.plp-filter-check {'), 'padding');

        expect(padding).not.toBeNull();

        const sides = padding.split(/\s+/);

        expect(sides.length).toBeGreaterThan(1);
        expect(sides[1]).toMatch(/^0(px)?$/);
    });

    it('starts the price controls on that same edge', () => {
        const slider = declaration(ruleFor('.plp-price-slider {'), 'margin');
        const sides = slider.split(/\s+/);

        expect(sides[1]).toMatch(/^0(px)?$/);
        expect(
            declaration(ruleFor('.plp-price-inputs {'), 'padding'),
        ).toBeNull();
    });

    /*
     * A checkbox is square. --radius-md is 8px, every radius token in this
     * system is 8px, and 8px on an 18px box reads as a circle — which is a
     * radio button's shape, and says "one of these" about a list where several
     * can be chosen.
     */
    it('draws a square checkbox, not a round one', () => {
        const radius = declaration(
            ruleFor('.plp-filter-check input {'),
            'border-radius',
        );

        expect(radius).toMatch(/^0(px)?$/);
    });
});
