import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

const css = readFileSync('resources/js/Layouts/MainLayout.css', 'utf8');

/** The block of a rule, searched from a given offset so a repeated selector
 *  inside a media query can be reached rather than the first one in the file. */
const ruleAfter = (selector, from = 0) => {
    const at = css.indexOf(selector, from);

    if (at === -1) return null;

    const open = css.indexOf('{', at);

    return { body: css.slice(open + 1, css.indexOf('}', open)), at };
};

const px = (body, property) => {
    const found = new RegExp(`${property}\\s*:\\s*(\\d+)px`).exec(body ?? '');

    return found ? Number(found[1]) : null;
};

/**
 * The header's category picker must show what it is set to.
 *
 * It is a fixed width so the search box beside it does not move when the
 * chosen name changes, which means the label has to fit inside whatever is
 * left after the padding, the leading glyph, the gaps and the chevron. On a
 * narrow header there was not enough: "All Tech" ellipsed away and the control
 * read as an empty box with two ornaments in it.
 *
 * The arithmetic is the guard. Nothing rendered in a test can measure text —
 * jsdom has no layout — so what is pinned is that the budget exists at all.
 */
describe('the header category picker', () => {
    const FONT = 0.82 * 16; // .search-category-select
    // "All Tech" in a 600-weight face: eight characters at roughly 0.55em.
    const LABEL = 'All Tech'.length * FONT * 0.55;

    const budget = (body, { icon }) => {
        const width = px(body, 'width');
        const pad = 12 * 2;
        const gaps = icon ? 6 * 2 : 6;
        const chevron = 16;

        return width - pad - gaps - chevron - (icon ? 14 : 0);
    };

    it('leaves room for the label on a wide header', () => {
        const wide = ruleAfter('.search-category-select {');

        expect(wide).not.toBeNull();
        expect(budget(wide.body, { icon: true })).toBeGreaterThan(LABEL);
    });

    /*
     * The narrow rule keeps the same width and drops the glyph, which is the
     * only one of the four that says nothing the label does not.
     *
     * Whether the glyph is there is read from the stylesheet rather than
     * assumed: asserting the budget against an icon-less layout while the icon
     * is still drawn would be arithmetic about a screen that does not exist,
     * and it passed happily when the hide-rule was taken back out.
     */
    it('leaves room for the label on a narrow header', () => {
        const wide = ruleAfter('.search-category-select {');
        const narrow = ruleAfter('.search-category-select {', wide.at + 1);

        expect(narrow).not.toBeNull();

        const glyphHidden =
            /\.search-category-select \.ui-select-icon \{\s*display:\s*none/.test(
                css,
            );

        expect(budget(narrow.body, { icon: !glyphHidden })).toBeGreaterThan(
            LABEL,
        );
    });

    it('drops the glyph rather than the words when space is short', () => {
        expect(css).toMatch(
            /\.search-category-select \.ui-select-icon \{\s*display:\s*none/,
        );
    });
});
