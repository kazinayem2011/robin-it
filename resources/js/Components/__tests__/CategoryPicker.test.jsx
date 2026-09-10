import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const get = vi.fn();

vi.mock('../../services/axiosInstance', () => ({
    default: { get: (...a) => get(...a) },
}));

const { default: CategoryPicker } = await import('../CategoryPicker');

/*
 * The query is set in one event rather than typed a character at a time. The
 * debounce still runs and the search still fires; what goes is userEvent's own
 * per-keystroke delay, which was enough to race the default one-second findBy
 * when the whole suite runs together.
 */
const type = (value) => {
    const box = screen.getByRole('textbox');

    // Focus first: the search only runs while the list is open, and opening it
    // is what focus does. A bare change event never gets there.
    fireEvent.focus(box);
    fireEvent.change(box, { target: { value } });
};

const ROWS = [
    { id: 396, name: 'All Laptop', path: 'Laptop' },
    { id: 411, name: 'Gaming Laptop', path: 'Laptop' },
];

/**
 * Typing into the picker and getting somewhere.
 *
 * The two on the product form are the same component in different modes: the
 * primary shelf takes one, "Also list under" takes several. The multi one was
 * reported as doing nothing at all when typed into.
 */
describe('CategoryPicker', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        get.mockResolvedValue({ data: ROWS });
    });

    it('searches on focus', async () => {
        const user = userEvent.setup();
        render(<CategoryPicker label="Category" onChange={vi.fn()} />);

        await user.click(screen.getByRole('textbox'));

        await waitFor(() => expect(get).toHaveBeenCalled());
    });

    it('searches again for what is typed', async () => {
        render(<CategoryPicker label="Category" onChange={vi.fn()} />);

        type('lap');

        await waitFor(() =>
            expect(get.mock.calls.at(-1)[1]).toEqual({ params: { q: 'lap' } }),
        );
    });

    it('shows what came back', async () => {
        render(<CategoryPicker label="Category" onChange={vi.fn()} />);

        type('lap');

        expect(
            await screen.findByText('Gaming Laptop', {}, { timeout: 4000 }),
        ).toBeTruthy();
    });

    /* Single mode hands back the id. */
    it('reports the chosen id', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<CategoryPicker label="Category" onChange={onChange} />);

        type('lap');
        await user.click(
            await screen.findByText('Gaming Laptop', {}, { timeout: 4000 }),
        );

        expect(onChange).toHaveBeenCalledWith(411);
    });

    // ── the one that was reported ────────────────────────────────────────────

    it('searches when typed into in multi mode', async () => {
        render(
            <CategoryPicker
                label="Also list under"
                multiple
                chips={[]}
                onChange={vi.fn()}
                onRemove={vi.fn()}
            />,
        );

        type('lap');

        await waitFor(() => expect(get).toHaveBeenCalled());
    });

    it('shows the results in multi mode', async () => {
        render(
            <CategoryPicker
                label="Also list under"
                multiple
                chips={[]}
                onChange={vi.fn()}
                onRemove={vi.fn()}
            />,
        );

        type('lap');

        expect(
            await screen.findByText('Gaming Laptop', {}, { timeout: 4000 }),
        ).toBeTruthy();
    });

    /* Multi mode hands back the whole row, because the caller draws a chip. */
    it('reports the whole category in multi mode', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(
            <CategoryPicker
                label="Also list under"
                multiple
                chips={[]}
                onChange={onChange}
                onRemove={vi.fn()}
            />,
        );

        type('lap');
        await user.click(
            await screen.findByText('Gaming Laptop', {}, { timeout: 4000 }),
        );

        expect(onChange).toHaveBeenCalledWith(
            expect.objectContaining({ id: 411, name: 'Gaming Laptop' }),
        );
    });

    // ── the label has to point at its own input ──────────────────────────────

    /**
     * The product form carries three of these — the primary shelf, the other
     * shelves, and the filter above the list. A fixed default id gave all
     * three the same one, so both labels pointed at the first input and
     * clicking "Also list under" put the cursor in Category.
     */
    it('gives each instance its own id', () => {
        render(
            <>
                <CategoryPicker label="Category" onChange={vi.fn()} />
                <CategoryPicker
                    label="Also list under"
                    multiple
                    chips={[]}
                    onChange={vi.fn()}
                />
            </>,
        );

        const ids = [
            ...document.querySelectorAll('.category-picker input'),
        ].map((n) => n.id);

        expect(ids).toHaveLength(2);
        expect(ids[0]).toBeTruthy();
        expect(new Set(ids).size).toBe(2);
    });

    it('points each label at the input beside it', () => {
        render(
            <>
                <CategoryPicker label="Category" onChange={vi.fn()} />
                <CategoryPicker
                    label="Also list under"
                    multiple
                    chips={[]}
                    onChange={vi.fn()}
                />
            </>,
        );

        for (const label of document.querySelectorAll(
            '.category-picker label',
        )) {
            const target = document.getElementById(label.getAttribute('for'));

            expect(
                target,
                `"${label.textContent.trim()}" points at nothing`,
            ).toBeTruthy();
            expect(label.closest('.category-picker').contains(target)).toBe(
                true,
            );
        }
    });

    /* A caller that wants to name it still can. */
    it('takes an id when one is given', () => {
        render(
            <CategoryPicker
                id="my-own-id"
                label="Category"
                onChange={vi.fn()}
            />,
        );

        expect(document.querySelector('.category-picker input').id).toBe(
            'my-own-id',
        );
    });
});
