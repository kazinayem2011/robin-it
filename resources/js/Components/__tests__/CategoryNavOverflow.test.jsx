import React from 'react';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';

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
 * The third level of a department that has fallen into "More".
 *
 * The bar holds fifteen departments and a laptop holds about eleven, so three
 * or four sit behind the "More" control — on this catalogue Accessories,
 * Gadget and Server & Storage, the three carrying the most brands. Their brand
 * chips were left undrawn there, which put 448 of them beyond reach of the
 * menu: every keyboard, mouse, headphone and earbud maker the shop sells.
 *
 * jsdom has no layout, so these ask what was built rather than where it sits.
 * The half that cannot be asked here — that the panel lands on screen — is the
 * clamping effect's, and it is exercised by the same code path either way.
 */
describe('a department behind "More"', () => {
    const chips = (names) =>
        names.map((name, i) => ({
            id: 900 + i,
            name,
            slug: name.toLowerCase(),
        }));

    const accessories = {
        id: 3,
        name: 'Accessories',
        slug: 'accessories',
        subcategories: [
            {
                id: 30,
                name: 'Keyboard',
                slug: 'accessories-keyboard',
                children: chips(['Keychron', 'Logitech', 'Rapoo']),
            },
            { id: 31, name: 'Mouse', slug: 'accessories-mouse', children: [] },
        ],
    };

    const categories = [
        { id: 1, name: 'Laptop', slug: 'laptop', subcategories: [] },
        { id: 2, name: 'Component', slug: 'component', subcategories: [] },
        accessories,
    ];

    /*
     * The bar measures its items to decide what fits, and nothing has a width
     * in jsdom, so the widths are dictated here: room for two departments plus
     * the "More" control, which pushes Accessories into it.
     *
     * Both of these are patched onto HTMLElement.prototype, which every suite
     * in the worker shares — left in place they report zero for the rest of the
     * run and fail unrelated files. Put back after each test.
     */
    const original = {
        rect: HTMLElement.prototype.getBoundingClientRect,
        clientWidth: Object.getOwnPropertyDescriptor(
            HTMLElement.prototype,
            'clientWidth',
        ),
    };

    const layOut = ({ available = 300, each = 120 } = {}) => {
        Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
            configurable: true,
            get() {
                return this.tagName === 'NAV' || this.tagName === 'UL'
                    ? available
                    : 0;
            },
        });

        HTMLElement.prototype.getBoundingClientRect = function () {
            const width = this.hasAttribute?.('data-cat-item') ? each : 0;

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
    };

    beforeEach(() => layOut());

    afterEach(() => {
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

    const itemFor = (name) => screen.getByText(name).closest('li');

    it('pushes the last department into More when the bar is full', () => {
        render(<CategoryNav categories={categories} />);

        expect(screen.getByText('More')).toBeInTheDocument();
        expect(itemFor('Accessories')).toHaveClass('cat-nav-moreitem');
    });

    /* The point of the change. */
    it('draws the brand chips of a department in More', () => {
        render(<CategoryNav categories={categories} />);

        const panel = itemFor('Accessories');

        expect(within(panel).getByText('Keychron')).toBeInTheDocument();
        expect(within(panel).getByText('Logitech')).toBeInTheDocument();
        expect(within(panel).getByText('Rapoo')).toBeInTheDocument();
    });

    it('gives each chip its own shelf link', () => {
        render(<CategoryNav categories={categories} />);

        const chip = within(itemFor('Accessories')).getByText('Keychron');

        expect(chip.closest('a')).toHaveAttribute(
            'href',
            expect.stringContaining('keychron'),
        );
    });

    /*
     * The look of the "More" list itself is not part of this. Changing its
     * panel to the one the bar uses altered its spacing and row height, which
     * was visible and unwanted; it keeps the class it has always had.
     */
    it('leaves the More panel the shape it was', () => {
        render(<CategoryNav categories={categories} />);

        const panel = itemFor('Accessories').querySelector('ul');

        expect(panel).toHaveClass('cat-nav-brands');
        expect(panel).not.toHaveClass('cat-nav-drop');
    });

    /* And a department still in the bar keeps the bar's panel. */
    it('leaves a department in the bar exactly as it was', () => {
        render(<CategoryNav categories={[accessories, categories[0]]} />);

        const item = itemFor('Accessories');

        expect(item).toHaveClass('cat-nav-item');
        expect(item.querySelector('ul')).toHaveClass('cat-nav-drop');
        expect(within(item).getByText('Keychron')).toBeInTheDocument();
    });
});
