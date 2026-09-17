import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

const css = readFileSync('resources/js/Layouts/AdminLayout.css', 'utf8');

const declaration = (rule, property) => {
    const found = new RegExp(`(?:^|[;{\\s])${property}\\s*:\\s*([^;]+)`).exec(
        rule ?? '',
    );

    return found ? found[1].trim() : null;
};

/** The declarations of one rule, by a selector that must appear verbatim. */
const ruleFor = (selector) => {
    const at = css.indexOf(`${selector} {`);

    if (at === -1) return null;

    const open = css.indexOf('{', at);
    const close = css.indexOf('}', open);

    return css.slice(open + 1, close);
};

/**
 * "Move to" beside the order's status dropdown, on one line.
 *
 * The dropdown's wrapper is display: contents, so its trigger is the flex item,
 * and a trigger is width: 100% by default — it took the whole row and squeezed
 * the label into "Move" over "to". The label also carried the field-hint's top
 * margin, which sat it below the control's centre.
 *
 * Read from the stylesheet because jsdom lays nothing out.
 */
describe('the order status "Move to" row', () => {
    it('keeps the label on one line, level with the control', () => {
        const rule = ruleFor('.admin-order-status-move > .admin-field-hint');

        expect(rule).not.toBeNull();
        expect(declaration(rule, 'white-space')).toBe('nowrap');
        expect(declaration(rule, 'flex-shrink')).toBe('0');
        expect(declaration(rule, 'margin-top')).toBe('0');
    });

    it('sizes the dropdown to its option rather than to the row', () => {
        const rule = ruleFor(
            '.admin-order-status-move .ui-select-trigger.admin-status-dropdown',
        );

        expect(rule).not.toBeNull();
        expect(declaration(rule, 'width')).toBe('auto');
    });
});
