import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import userEvent from '@testing-library/user-event';
import ProductFilters from '../ProductFilters';

const FACETS = {
    min_price: 1000,
    max_price: 90000,
    total: 12,
    /*
     * Nine, because the brand search only appears above eight — below that the
     * list is shorter than the box that would filter it.
     */
    brands: [
        { id: 1, name: 'ASUS', slug: 'asus' },
        { id: 2, name: 'MSI', slug: 'msi' },
        { id: 3, name: 'Corsair', slug: 'corsair' },
        { id: 4, name: 'Dell', slug: 'dell' },
        { id: 5, name: 'HP', slug: 'hp' },
        { id: 6, name: 'Lenovo', slug: 'lenovo' },
        { id: 7, name: 'Gigabyte', slug: 'gigabyte' },
        { id: 8, name: 'Intel', slug: 'intel' },
        { id: 9, name: 'AMD', slug: 'amd' },
    ],
};

const headings = () =>
    [...document.querySelectorAll('.plp-filter-legend h4')].map((h) =>
        h.textContent.trim(),
    );

const body = () => document.querySelector('.plp-filters-body');
const skeletons = () =>
    document.querySelectorAll('.plp-filter-skeleton-rows').length;

describe('ProductFilters loading and busy states', () => {
    /*
     * The very first listing of a session has no facets at all, so there is
     * nothing to keep on screen and placeholders are the honest answer.
     */
    it('shows placeholders only when there is nothing to show', () => {
        render(<ProductFilters facets={null} value={{}} loading />);

        expect(skeletons()).toBeGreaterThan(0);
        expect(screen.queryByText('ASUS')).not.toBeInTheDocument();
    });

    /*
     * Refreshing counts must not replace what the shopper is reading. This is
     * the case that used to flash placeholders on every category click.
     */
    it('keeps the filters on screen while refreshing them', () => {
        render(<ProductFilters facets={FACETS} value={{}} busy />);

        expect(skeletons()).toBe(0);
        expect(screen.getByText('ASUS')).toBeInTheDocument();
    });

    it('takes itself out of action while refreshing', () => {
        render(<ProductFilters facets={FACETS} value={{}} busy />);

        expect(body()).toHaveClass('is-busy');
        expect(body()).toHaveAttribute('inert');
        expect(body()).toHaveAttribute('aria-busy', 'true');
    });

    it('is interactive again once the refresh lands', () => {
        const { rerender } = render(
            <ProductFilters facets={FACETS} value={{}} busy />,
        );

        rerender(<ProductFilters facets={FACETS} value={{}} busy={false} />);

        expect(body()).not.toHaveClass('is-busy');
        expect(body()).not.toHaveAttribute('inert');
    });

    /*
     * Placeholders win over dimming: dimming a skeleton reads as broken, and
     * there is nothing there to protect from a second click anyway.
     */
    it('does not dim the placeholders on a cold load', () => {
        render(<ProductFilters facets={null} value={{}} loading busy />);

        expect(skeletons()).toBeGreaterThan(0);
        expect(body()).not.toHaveClass('is-busy');
        expect(body()).not.toHaveAttribute('inert');
    });

    it('shows the filters plainly when idle', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(skeletons()).toBe(0);
        expect(body()).not.toHaveClass('is-busy');
        expect(screen.getByText('MSI')).toBeInTheDocument();
    });
});

/**
 * What the panel is for.
 *
 * It led with a category tree and a search box for it, which is navigation the
 * shop offers three other ways — the mega menu, the breadcrumb, and the row of
 * makers across the top of the shelf. A panel for narrowing a selection should
 * not open with the one control that leaves it.
 */
/**
 * A placeholder may only stand in for a section that is coming.
 *
 * The skeleton led with a Category group and kept leading with one after the
 * category tree was taken out of the panel, so a shopper watched a section
 * load that was never going to arrive. Comparing the two lists rather than
 * counting them means the next section to leave the panel cannot leave a
 * placeholder behind either.
 */
describe('the filter panel while it loads', () => {
    /* The skeleton's own groups are the ones holding placeholder rows. */
    const placeholderHeadings = () =>
        [...document.querySelectorAll('.plp-filter-group')]
            .filter((group) => group.querySelector('.plp-filter-skeleton-rows'))
            .map((group) => group.querySelector('h4')?.textContent.trim());

    it('names no section the panel does not have', () => {
        const { unmount } = render(<ProductFilters value={{}} loading />);
        const placeholders = placeholderHeadings();
        unmount();

        render(<ProductFilters facets={FACETS} value={{}} />);
        const real = headings();

        expect(placeholders.length).toBeGreaterThan(0);
        expect(placeholders).not.toContain('Category');

        for (const name of placeholders) {
            expect(real, `${name} is a placeholder for nothing`).toContain(
                name,
            );
        }
    });

    /*
     * Brand is the only one worth a placeholder: it waits on the server, and
     * Price and Availability draw themselves at once.
     */
    it('stands in for Brand', () => {
        render(<ProductFilters value={{}} loading />);

        expect(placeholderHeadings()).toEqual(['Brand']);
    });
});

describe('what the filter panel offers', () => {
    it('opens on Price, not on Category', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(headings()[0]).toBe('Price');
        expect(headings()).not.toContain('Category');
    });

    it('offers Price, Brand and Availability', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(headings()).toEqual(
            expect.arrayContaining(['Price', 'Brand', 'Availability']),
        );
    });

    /* The category search went with the tree; the brand one is a different box. */
    it('has no category search left behind', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(screen.queryByLabelText('Search categories')).toBeNull();
        expect(screen.queryByPlaceholderText('Search categories')).toBeNull();
    });

    it('keeps the brand search', async () => {
        const user = userEvent.setup();
        render(<ProductFilters facets={FACETS} value={{}} />);

        const box = screen.getByLabelText('Search brands');
        await user.type(box, 'cor');

        expect(screen.getByText('Corsair')).toBeInTheDocument();
        expect(screen.queryByText('ASUS')).toBeNull();
    });

    /* Several at once: a shopper comparing ASUS against MSI picks both. */
    it('reports a brand as chosen, and two of them at once', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(
            <ProductFilters facets={FACETS} value={{}} onChange={onChange} />,
        );

        await user.click(screen.getByLabelText('ASUS'));
        expect(onChange).toHaveBeenLastCalledWith(
            expect.objectContaining({ brand_ids: [1] }),
        );

        onChange.mockClear();
        render(
            <ProductFilters
                facets={FACETS}
                value={{ brand_ids: [1] }}
                onChange={onChange}
            />,
        );

        await user.click(screen.getAllByLabelText('MSI')[1]);
        expect(onChange).toHaveBeenLastCalledWith(
            expect.objectContaining({ brand_ids: [1, 2] }),
        );
    });
});

/**
 * The price range, as a track with a handle at each end.
 *
 * It was a line of text — "৳3,500 – ৳3,500 available" — which is what that
 * shelf's bounds honestly were and no help to anybody, and two number boxes
 * underneath that a shopper had to guess values for.
 */
/**
 * The Brand filter, and the one page that should not have it.
 *
 * On a maker's own shelf — /shop/asus-all-laptop — every product is that
 * maker's, so the filter is a single checkbox that changes nothing. The row of
 * makers across the top of the grid is how somebody moves to another one,
 * which is what the trade does and what Star Tech does.
 */
describe('the Brand filter', () => {
    it('is offered on an ordinary shelf, several at a time', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(headings()).toContain('Brand');
        expect(screen.getByLabelText('ASUS')).toBeTruthy();
        expect(screen.getByLabelText('MSI')).toBeTruthy();
    });

    it('is withheld on a maker’s own shelf', () => {
        render(<ProductFilters facets={FACETS} value={{}} hideBrand />);

        expect(headings()).not.toContain('Brand');
        expect(screen.queryByLabelText('ASUS')).toBeNull();
    });

    /* The rest of the panel is untouched: price and stock still narrow. */
    it('leaves the other filters alone there', () => {
        render(<ProductFilters facets={FACETS} value={{}} hideBrand />);

        expect(headings()).toEqual(
            expect.arrayContaining(['Price', 'Availability']),
        );
    });
});

describe('the price slider', () => {
    const handles = () => [
        screen.getByLabelText('Minimum price', {
            selector: 'input[type="range"]',
        }),
        screen.getByLabelText('Maximum price', {
            selector: 'input[type="range"]',
        }),
    ];

    it('replaces the line of text about what is available', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(screen.queryByText(/available/)).toBeNull();
        expect(document.querySelector('.plp-price-slider')).toBeTruthy();
    });

    /*
     * From nothing to the dearest thing on the shelf, the way the trade draws
     * it. Starting at the cheapest would make the left handle's resting place
     * mean "no minimum" and "the minimum there is" at the same time.
     */
    it('runs from zero to the dearest thing on the shelf', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        const [low, high] = handles();
        expect(low.min).toBe('0');
        expect(high.max).toBe('90000');
    });

    it('rests each handle at its own end until it is moved', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        const [low, high] = handles();
        expect(low.value).toBe('0');
        expect(high.value).toBe('90000');
    });

    it('places the handles where the shopper left them', () => {
        render(
            <ProductFilters
                facets={FACETS}
                value={{ min_price: 20000, max_price: 60000 }}
            />,
        );

        const [low, high] = handles();
        expect(low.value).toBe('20000');
        expect(high.value).toBe('60000');
    });

    /* Dragged past each other, the handles stop rather than invert the range. */
    it('will not let the handles cross', () => {
        render(
            <ProductFilters
                facets={FACETS}
                value={{ min_price: 20000, max_price: 60000 }}
            />,
        );

        const [low, high] = handles();

        // Dragging the low handle above the high one pins it, not past it.
        fireEvent.change(low, { target: { value: '80000' } });
        expect(Number(low.value)).toBeLessThanOrEqual(Number(high.value));
        expect(low.value).toBe('60000');

        // And the same the other way.
        fireEvent.change(high, { target: { value: '0' } });
        expect(Number(high.value)).toBeGreaterThanOrEqual(Number(low.value));
    });

    /*
     * A hundred steps across whatever the shelf spans, so the drag feels the
     * same on a cable shelf as on a laptop one.
     */
    it('steps in proportion to the shelf, not in single taka', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(Number(handles()[0].step)).toBe(900);
    });

    it('draws no slider when nothing on the shelf has a price', () => {
        render(
            <ProductFilters
                facets={{ ...FACETS, min_price: 0, max_price: 0 }}
                value={{}}
            />,
        );

        expect(document.querySelector('.plp-price-slider')).toBeNull();
    });

    /**
     * The ceiling is the dearest thing still reachable, so choosing a brand
     * can lower it beneath a maximum the shopper had already set — pick
     * 60,000 on a shelf topping out at 132,000, then narrow to a maker whose
     * dearest is 9,500. The handle pins to the new end rather than sitting
     * off the track or reading above the slider's own maximum.
     */
    it('pins a handle left beyond a lowered ceiling', () => {
        render(
            <ProductFilters
                facets={{ ...FACETS, max_price: 9500 }}
                value={{ min_price: 0, max_price: 60000 }}
            />,
        );

        const high = screen.getByLabelText('Maximum price', {
            selector: 'input[type="range"]',
        });

        expect(high.max).toBe('9500');
        expect(high.value).toBe('9500');

        /*
         * The value above is the input's own doing — a range input clamps
         * itself to its max. What needs clamping is the filled span, which is
         * positioned by percentage: 60,000 of a 9,500 track is 631%, and the
         * fill would run off the end of it.
         */
        const fill = document.querySelector('.plp-price-track-fill');
        const right = parseFloat(fill.style.right);

        expect(right).toBeGreaterThanOrEqual(0);
        expect(right).toBeLessThanOrEqual(100);
    });

    /* The boxes stay: a slider cannot be told an exact figure. */
    it('keeps the number boxes alongside it', () => {
        render(<ProductFilters facets={FACETS} value={{}} />);

        expect(
            screen.getByLabelText('Minimum price', {
                selector: 'input[type="number"]',
            }),
        ).toBeTruthy();
    });
});
