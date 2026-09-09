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
        const rule = ruleFor('.plp-filter-check {');
        const px = (value) => Number((/-?\d+/.exec(value ?? '') ?? [0])[0]);

        const inset = px(declaration(rule, 'padding').split(/\s+/)[1]);
        const pull = px(declaration(rule, 'margin-inline'));

        /*
         * The two cancel: the padding widens the hover background and the
         * margin takes the content back to the heading's own left edge. What
         * matters is the sum, not either number.
         */
        expect(inset).toBeGreaterThan(0);
        expect(inset + pull).toBe(0);
    });

    /* And the highlight is wider than the words in it. */
    it('reaches its highlight past the text on both sides', () => {
        const rule = ruleFor('.plp-filter-check {');

        expect(declaration(rule, 'border-radius')).toBe('var(--radius-sm)');
        expect(declaration(rule, 'margin-inline')).toMatch(/^-\d+px$/);
    });

    it('starts the price controls on that same edge', () => {
        const slider = declaration(ruleFor('.plp-price-slider {'), 'margin');
        const sides = slider.split(/\s+/);

        expect(sides[1]).toMatch(/^0(px)?$/);
        expect(
            declaration(ruleFor('.plp-price-inputs {'), 'padding'),
        ).toBeNull();
    });

    /* Rounded to the 8px this system rounds everything to. */
    it('rounds the checkbox to the system radius', () => {
        const radius = declaration(
            ruleFor('.plp-filter-check input {'),
            'border-radius',
        );

        expect(radius).toBe('var(--radius-sm)');
    });

    /**
     * The scroller is as wide as the rows in it.
     *
     * The rows bleed 10px either side, and `overflow-y: auto` permits overflow
     * sideways too — so the extra 20px put a horizontal scrollbar across the
     * bottom of the brand list. The scroller has to be widened by the same
     * amount the rows are, or there is something to scroll to.
     */
    it('leaves the brand list nothing to scroll sideways', () => {
        const rows = ruleFor('.plp-filter-check {');
        const scroller = ruleFor('.plp-filter-scroll {');
        const px = (value) => Number((/-?\d+/.exec(value ?? '') ?? [0])[0]);

        const bleed = -px(declaration(rows, 'margin-inline'));
        const room = -px(declaration(scroller, 'margin-inline'));

        expect(bleed).toBeGreaterThan(0);
        expect(room).toBe(bleed);
        expect(px(declaration(scroller, 'padding-inline'))).toBe(bleed);
        expect(declaration(scroller, 'overflow-x')).toBe('hidden');
    });
});
