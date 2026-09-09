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

    /**
     * A rounded square rather than a circle.
     *
     * The box is 18px. Every radius token in this system is 8px, which is
     * within a pixel of half that — so any of them draws a circle here, which
     * is a radio button's shape and says "one of these" about a list where
     * several can be picked.
     *
     * Asserted against the box's own size rather than against the number 4,
     * so the shape is what is pinned: anything from a third of the width
     * upwards stops reading as a square.
     */
    it('draws the checkbox as a rounded square, not a circle', () => {
        const box = 18;
        const radius = declaration(
            ruleFor('.plp-filter-check input {'),
            'border-radius',
        );

        expect(radius).toMatch(/^\d+px$/);
        expect(Number.parseInt(radius, 10)).toBeGreaterThan(0);
        expect(Number.parseInt(radius, 10)).toBeLessThan(box / 3);
    });

    /*
     * The number needs the linter's marker, and the marker is matched by line,
     * so it has to stay on the declaration's own line.
     */
    it('carries its exemption on the same line as the value', () => {
        const line = css
            .split('\n')
            .find((row) => row.includes('radius-exempt'));

        expect(line).toBeDefined();
        expect(line).toMatch(/border-radius:/);
        expect(line.length).toBeLessThanOrEqual(80);
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
