import React from 'react';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    usePage: () => ({ props: {}, url: '/' }),
}));

const { default: CategoryNav } = await import('../CategoryNav');

/**
 * A department opened from inside "More" is fitted to the window.
 *
 * Gadget, the third entry in "More", opened its list beside its own row at
 * fourteen rows a column and ran off the bottom of a laptop screen, with Power
 * Bank and everything after it out of reach. The fix takes columns first, and
 * moves the list up only by what columns cannot fix — never past the left edge,
 * which is where taking columns alone put Accessories at 1000px.
 *
 * jsdom has no layout, so the panel is given one: every row is 30px, every
 * column 190px, its right edge sits against "More" at x=640 and its top level
 * with its row at y=256 — the numbers measured in Edge at 1000x600. What is
 * under test is the effect's arithmetic and that nothing re-renders it away;
 * the Edge runs are where it was checked against real pixels.
 */
describe('a department opened from More', () => {
    const ROW = 30;
    const COLUMN = 190;
    const RIGHT = 640;
    const ROW_TOP = 262; // the row; the panel's CSS top of -6px puts it at 256
    const CSS_ROWS = 14;

    const department = (count) => ({
        id: 7,
        name: 'Gadget',
        slug: 'gadget',
        subcategories: Array.from({ length: count }, (_, i) => ({
            id: 700 + i,
            name: `Gadget ${i + 1}`,
            slug: `gadget-${i + 1}`,
            children: [],
        })),
    });

    const categories = (count) => [
        { id: 1, name: 'Laptop', slug: 'laptop', subcategories: [] },
        { id: 2, name: 'Component', slug: 'component', subcategories: [] },
        department(count),
    ];

    const original = {
        rect: HTMLElement.prototype.getBoundingClientRect,
        clientWidth: Object.getOwnPropertyDescriptor(
            HTMLElement.prototype,
            'clientWidth',
        ),
        computed: window.getComputedStyle,
        innerHeight: window.innerHeight,
    };

    const rowsOf = (el) =>
        Number(el.style.gridTemplateRows.match(/\d+/)?.[0] ?? CSS_ROWS);

    /* Where the panel would be drawn, given what the effect has written. */
    const boxOf = (el) => {
        const rows = rowsOf(el);
        const columns = Math.ceil(el.children.length / rows);
        const top = ROW_TOP + (parseFloat(el.style.top) || -6);
        const width = columns * COLUMN;
        // A min-content row with nothing in it has no height.
        const height = Math.min(rows, el.children.length) * ROW + 16;

        return {
            top,
            bottom: top + height,
            left: RIGHT - width,
            right: RIGHT,
            width,
            height,
            x: RIGHT - width,
            y: top,
            toJSON: () => ({}),
        };
    };

    beforeEach(() => {
        window.innerHeight = 600;

        // Room for two departments and "More", so Gadget falls into it.
        Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
            configurable: true,
            get() {
                return this.tagName === 'UL' ? 300 : 0;
            },
        });

        HTMLElement.prototype.getBoundingClientRect = function () {
            if (this.dataset?.morePanel) return boxOf(this);

            const width = this.hasAttribute?.('data-cat-item') ? 120 : 0;

            return {
                width,
                height: 0,
                top: 0,
                left: 0,
                right: width,
                bottom: 0,
                x: 0,
                y: 0,
                toJSON: () => ({}),
            };
        };

        vi.spyOn(window, 'getComputedStyle').mockImplementation((el) =>
            el.dataset?.morePanel
                ? {
                      gridTemplateRows: Array(CSS_ROWS)
                          .fill(`${ROW}px`)
                          .join(' '),
                      top: el.style.top || '-6px',
                  }
                : original.computed(el),
        );
    });

    afterEach(() => {
        vi.restoreAllMocks();
        window.innerHeight = original.innerHeight;
        HTMLElement.prototype.getBoundingClientRect = original.rect;

        if (original.clientWidth) {
            Object.defineProperty(
                HTMLElement.prototype,
                'clientWidth',
                original.clientWidth,
            );
        } else {
            delete HTMLElement.prototype.clientWidth;
        }
    });

    const item = () => screen.getByText('Gadget').closest('li');
    const panel = () => item().querySelector('[data-more-panel]');
    const open = () => fireEvent.mouseEnter(item());

    it('is in More, which is what this is about', () => {
        render(<CategoryNav categories={categories(16)} />);

        expect(item()).toHaveClass('cat-nav-moreitem');
    });

    /* Seventeen rows with "Show all": fourteen a column ran to y=732. */
    it('takes columns until it fits, and stays level with its row', () => {
        render(<CategoryNav categories={categories(16)} />);
        open();

        expect(rowsOf(panel())).toBe(10);
        expect(panel().style.top).toBe('');
        expect(boxOf(panel()).bottom).toBeLessThanOrEqual(600 - 12);
    });

    it('leaves a list that already fits exactly as the stylesheet drew it', () => {
        render(<CategoryNav categories={categories(5)} />);
        open();

        expect(panel().style.gridTemplateRows).toBe('');
        expect(panel().style.top).toBe('');
    });

    /*
     * Forty entries: a fourth column would cross the left edge, so it keeps
     * the stylesheet's fourteen and moves up by what is still hanging off —
     * and only by that, so its span still takes in the row that opened it.
     */
    it('stops short of the left edge, and moves up instead', () => {
        render(<CategoryNav categories={categories(40)} />);
        open();

        const box = boxOf(panel());

        expect(panel().style.gridTemplateRows).toBe('');
        expect(box.left).toBeGreaterThanOrEqual(12);
        expect(box.bottom).toBe(600 - 12);
        expect(box.top).toBeLessThanOrEqual(ROW_TOP);
        expect(box.bottom).toBeGreaterThanOrEqual(ROW_TOP + ROW);
    });

    /*
     * The effect writes to the element, not through React. Hovering a row
     * inside re-renders the whole bar; if React owned the style it would put
     * the stylesheet's layout back mid-hover.
     */
    it('keeps its fit while the rows inside it re-render', () => {
        render(<CategoryNav categories={categories(40)} />);
        open();

        const fitted = panel().getAttribute('style');

        for (const row of item().querySelectorAll('.cat-nav-subitem')) {
            fireEvent.mouseEnter(row);
        }

        expect(panel().getAttribute('style')).toBe(fitted);
    });

    /* Measured from scratch every time: reopening must not move it further. */
    it('lands in the same place every time it is opened', () => {
        render(<CategoryNav categories={categories(40)} />);
        open();

        const first = panel().getAttribute('style');

        for (let i = 0; i < 3; i++) {
            fireEvent.mouseEnter(screen.getByText('More').closest('li'));
            fireEvent.mouseLeave(screen.getByText('More').closest('li'));
            open();
        }

        expect(panel().getAttribute('style')).toBe(first);
    });
});
