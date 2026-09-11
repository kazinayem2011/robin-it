import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

const css = readFileSync('resources/css/app.css', 'utf8');

const ruleFor = (selector) => {
    const at = css.indexOf(selector);

    if (at === -1) return null;

    const open = css.indexOf('{', at);

    return css.slice(open + 1, css.indexOf('}', open));
};

/**
 * A card must not grow the day it gets its first review.
 *
 * The row holds one of two things: a star, a score and a count, or the words
 * "No reviews yet". The first carries a 13px icon the second does not, so the
 * row was taller once a review existed — and a shelf mixing reviewed and
 * unreviewed products had its prices sitting at two different heights.
 *
 * Reserving the height is what keeps the two interchangeable, which is also
 * what makes "No reviews yet" safe to keep: the space is spent either way, so
 * saying so costs nothing and is honest about a product nobody has rated.
 *
 * Asserted against the stylesheet because jsdom has no layout.
 */
describe('the product card rating row', () => {
    it('reserves its height so both states are the same size', () => {
        const rule = ruleFor('.product-rating-row {');

        expect(rule).not.toBeNull();
        expect(/min-height\s*:\s*\d/.test(rule)).toBe(true);
    });
});
