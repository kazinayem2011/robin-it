import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const reload = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();
const deleteCategory = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { reload },
    Head: () => null,
    Link: ({ children }) => <span>{children}</span>,
    usePage: () => ({ props: {}, url: '/admin/categories' }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));

vi.mock('../Components/CategoryFormModal', () => ({ default: () => null }));

vi.mock('@/Components/Toast', () => ({
    toast: { error: toastError, success: toastSuccess },
}));

vi.mock('@/services', () => ({
    adminService: {
        deleteCategory,
        moveCategory: vi.fn(),
        moveCategoryTo: vi.fn(),
    },
}));

const { default: Categories } = await import('../Categories');

/**
 * Being told why a category will not delete.
 *
 * The API refuses when products would be destroyed with the shelf, and says
 * exactly how many are in the way and what to do about them —
 * products.category_id cascades, so deleting the shelf would take them
 * permanently. The page replaced that whole sentence with "Failed to delete
 * category.", which is indistinguishable from the server being down, and left
 * the admin with nothing to act on.
 */
describe('Categories — a delete that is refused', () => {
    const categories = [
        {
            id: 1,
            name: 'Desktop',
            slug: 'desktop',
            is_active: true,
            children: [
                { id: 10, name: 'Acer', slug: 'acer-brand-pc', children: [] },
            ],
        },
        {
            id: 2,
            name: 'Laptop',
            slug: 'laptop',
            is_active: true,
            children: [],
        },
    ];

    const REFUSAL =
        "'Acer' still holds 1 product(s), including its subcategories. " +
        'Move or delete those products first — deleting the category would ' +
        'remove them permanently.';

    beforeEach(() => vi.clearAllMocks());

    const openDeleteFor = async (name) => {
        const user = userEvent.setup();
        render(<Categories categories={categories} parentOptions={[]} />);

        await user.click(screen.getByLabelText(`Delete ${name}`));
        return user;
    };

    it('shows the server’s reason, not a generic failure', async () => {
        deleteCategory.mockRejectedValue(new Error(REFUSAL));

        const user = await openDeleteFor('Acer');
        await user.click(screen.getByRole('button', { name: /Yes, Delete/i }));

        await waitFor(() => expect(toastError).toHaveBeenCalled());
        expect(toastError.mock.calls[0][0]).toBe(REFUSAL);
        expect(toastError.mock.calls[0][0]).not.toMatch(/Failed to delete/);
    });

    /* An error with nothing to say still has to say something. */
    it('falls back to its own sentence when the server sends none', async () => {
        deleteCategory.mockRejectedValue(new Error(''));

        const user = await openDeleteFor('Acer');
        await user.click(screen.getByRole('button', { name: /Yes, Delete/i }));

        await waitFor(() => expect(toastError).toHaveBeenCalled());
        expect(toastError.mock.calls[0][0]).toBe(
            'Could not delete that category.',
        );
    });

    it('still reports a delete that works', async () => {
        deleteCategory.mockResolvedValue({});

        const user = await openDeleteFor('Acer');
        await user.click(screen.getByRole('button', { name: /Yes, Delete/i }));

        await waitFor(() => expect(toastSuccess).toHaveBeenCalled());
        expect(toastError).not.toHaveBeenCalled();
        expect(reload).toHaveBeenCalled();
    });

    /**
     * The warning has to describe what happens. Naming only the
     * subcategories left an admin braced for products to be destroyed —
     * products are the one thing it will not take.
     */
    it('warns about subcategories and says products stop the delete', async () => {
        await openDeleteFor('Acer');

        const box = screen.getByText(/Any subcategories beneath it/);
        expect(box.textContent).toMatch(/nothing is deleted/);
        expect(box.textContent).toMatch(/how many to move first/);
    });
});
