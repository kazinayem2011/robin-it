import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

/**
 * The homepage builder's two sides finish level.
 *
 * The three component rows and the LIVE SUMMARY beside them do not agree on a
 * natural height — measured at 1440px, 313 against 342 — so with
 * align-items: start the card hung 29px below the last row and the panel ended
 * on a ragged edge.
 *
 * Three declarations produce the fix together: the grid stretches its columns,
 * the parts column fills the height it is given, and the rows share the slack.
 * Any one alone does nothing, which is why they are asserted together.
 *
 * jsdom computes no layout, so the equal heights themselves were measured in a
 * browser: 342 and 342 at 1440 and 1280, and independent below 1200 where the
 * grid stacks and matching them would only add empty space.
 */
describe('homepage builder panel alignment', () => {
    const css = readFileSync('resources/js/Pages/Welcome.css', 'utf8');

    const ruleFor = (selector) => {
        const at = css.indexOf(`${selector} {`);

        return css
            .slice(at, css.indexOf('}', at))
            .replace(/\/\*[\s\S]*?\*\//g, '');
    };

    it('stretches the columns rather than letting each take its own height', () => {
        const grid = ruleFor('.builder-interactive-grid');

        expect(grid).toMatch(/align-items:\s*stretch/);
        expect(grid).not.toMatch(/align-items:\s*(start|flex-start)/);
    });

    it('lets the parts column fill the height the grid gives it', () => {
        expect(ruleFor('.builder-parts')).toMatch(/height:\s*100%/);
    });

    /* Without this the column fills but the rows do not, leaving the slack in
       a gap under the last row instead of spread between them. */
    it('shares the slack between the three rows', () => {
        expect(ruleFor('.builder-part-row')).toMatch(/flex:\s*1/);
    });
});
