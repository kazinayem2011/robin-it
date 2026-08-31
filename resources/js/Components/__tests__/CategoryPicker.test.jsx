import React, { useState } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import CategoryPicker from '../CategoryPicker';

vi.mock('../../services/axiosInstance', () => ({
    default: {
        get: vi.fn(() =>
            Promise.resolve({
                data: [
                    { id: 1307, name: 'Mouse', path: 'Accessories' },
                    { id: 1308, name: 'Mouse Pad', path: 'Accessories' },
                ],
            }),
        ),
    },
}));

/**
 * Picking a category, and still being able to read what you picked.
 *
 * The parent only knows the id: choosing calls onChange(id), which re-ran the
 * effect that syncs from props and replaced the row just selected with
 * {id, name: initialLabel}. On a create form there is no initialLabel, so the
 * field you had this moment filled in fell back to reading "Category #1307".
 */
describe('CategoryPicker', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    /* The parent that the real form is: it stores only the id. */
    const Host = () => {
        const [value, setValue] = useState('');

        return (
            <CategoryPicker
                value={value}
                onChange={setValue}
                label="Category"
            />
        );
    };

    it('keeps showing the category that was picked', async () => {
        const user = userEvent.setup();
        render(<Host />);

        await user.click(screen.getByRole('textbox'));
        await user.type(screen.getByRole('textbox'), 'mouse');

        // Matched on text rather than accessible name: the path is an <em>
        // inside the button, so the computed name carries its own spacing.
        const options = await screen.findAllByRole('button');
        const option = options.find((b) =>
            /Mouse$/.test(b.textContent.replace(/\s+/g, ' ').trim()),
        );

        expect(option).toBeTruthy();
        await user.click(option);

        await waitFor(() => {
            expect(
                screen.queryByText(/Category #1307/),
            ).not.toBeInTheDocument();
        });

        expect(screen.getByText('Mouse')).toBeInTheDocument();
    });

    /**
     * Still follows the parent when the value changes from outside — opening
     * the form on a different product has to replace what is shown.
     */
    it('follows an id set from outside', async () => {
        const { rerender } = render(
            <CategoryPicker value="" onChange={() => {}} initialLabel="" />,
        );

        rerender(
            <CategoryPicker
                value={42}
                onChange={() => {}}
                initialLabel="Graphics Card"
            />,
        );

        expect(await screen.findByText('Graphics Card')).toBeInTheDocument();
    });

    it('clears when the value is removed', async () => {
        const { rerender } = render(
            <CategoryPicker
                value={42}
                onChange={() => {}}
                initialLabel="Graphics Card"
            />,
        );

        expect(screen.getByText('Graphics Card')).toBeInTheDocument();

        rerender(
            <CategoryPicker value="" onChange={() => {}} initialLabel="" />,
        );

        expect(screen.queryByText('Graphics Card')).not.toBeInTheDocument();
    });
});
