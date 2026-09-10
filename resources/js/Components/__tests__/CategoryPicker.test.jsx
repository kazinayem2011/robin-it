import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const get = vi.fn();

vi.mock('../../services/axiosInstance', () => ({
    default: { get: (...a) => get(...a) },
}));

const { default: CategoryPicker } = await import('../CategoryPicker');

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
        const user = userEvent.setup();
        render(<CategoryPicker label="Category" onChange={vi.fn()} />);

        await user.type(screen.getByRole('textbox'), 'lap');

        await waitFor(() =>
            expect(get.mock.calls.at(-1)[1]).toEqual({ params: { q: 'lap' } }),
        );
    });

    it('shows what came back', async () => {
        const user = userEvent.setup();
        render(<CategoryPicker label="Category" onChange={vi.fn()} />);

        await user.type(screen.getByRole('textbox'), 'lap');

        expect(await screen.findByText('Gaming Laptop')).toBeTruthy();
    });

    /* Single mode hands back the id. */
    it('reports the chosen id', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<CategoryPicker label="Category" onChange={onChange} />);

        await user.type(screen.getByRole('textbox'), 'lap');
        await user.click(await screen.findByText('Gaming Laptop'));

        expect(onChange).toHaveBeenCalledWith(411);
    });

    // ── the one that was reported ────────────────────────────────────────────

    it('searches when typed into in multi mode', async () => {
        const user = userEvent.setup();
        render(
            <CategoryPicker
                label="Also list under"
                multiple
                chips={[]}
                onChange={vi.fn()}
                onRemove={vi.fn()}
            />,
        );

        await user.type(screen.getByRole('textbox'), 'lap');

        await waitFor(() => expect(get).toHaveBeenCalled());
    });

    it('shows the results in multi mode', async () => {
        const user = userEvent.setup();
        render(
            <CategoryPicker
                label="Also list under"
                multiple
                chips={[]}
                onChange={vi.fn()}
                onRemove={vi.fn()}
            />,
        );

        await user.type(screen.getByRole('textbox'), 'lap');

        expect(await screen.findByText('Gaming Laptop')).toBeTruthy();
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

        await user.type(screen.getByRole('textbox'), 'lap');
        await user.click(await screen.findByText('Gaming Laptop'));

        expect(onChange).toHaveBeenCalledWith(
            expect.objectContaining({ id: 411, name: 'Gaming Laptop' }),
        );
    });
});
