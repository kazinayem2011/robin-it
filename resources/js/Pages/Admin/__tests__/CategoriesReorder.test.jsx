import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

/* The page under test is the reordering, not the shell or the modals. */
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    Head: () => null,
    Link: ({ children }) => <span>{children}</span>,
    usePage: () => ({ props: {}, url: '/admin/categories' }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));

vi.mock('../Components/CategoryFormModal', () => ({ default: () => null }));
vi.mock('../Components/CategoryDeleteModal', () => ({ default: () => null }));

vi.mock('@/Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

vi.mock('@/services', () => ({
    adminService: { moveCategory: vi.fn(), moveCategoryTo: vi.fn() },
}));

const { default: Categories } = await import('../Categories');

/**
 * Reordering is off while the tree is filtered.
 *
 * A filtered list is a subset, so the row above the one on screen may not be
 * the row above it on the shelf. Dragging then moves a category past rows
 * nobody can see, and the arrows sit enabled at ends that are not the ends.
 *
 * The page worked this out into `canReorder` and then rendered the cards
 * without it, so every card stayed draggable through a search.
 */
describe('Categories — reordering while filtered', () => {
    const categories = [
        {
            id: 1,
            name: 'Component',
            slug: 'component',
            is_active: true,
            children: [
                { id: 10, name: 'Processor', slug: 'processor', children: [] },
            ],
        },
        {
            id: 2,
            name: 'Laptop',
            slug: 'laptop',
            is_active: true,
            children: [],
        },
        {
            id: 3,
            name: 'Monitor',
            slug: 'monitor',
            is_active: true,
            children: [],
        },
    ];

    const draggable = () => document.querySelectorAll('[draggable="true"]');

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('lets an unfiltered tree be dragged', () => {
        render(<Categories categories={categories} parentOptions={[]} />);

        expect(draggable().length).toBeGreaterThan(0);
        expect(screen.getAllByTitle('Drag to reorder').length).toBeGreaterThan(
            0,
        );
    });

    it('stops the cards being draggable once a search filters them', async () => {
        const user = userEvent.setup();
        render(<Categories categories={categories} parentOptions={[]} />);

        await user.type(
            screen.getByPlaceholderText('Search categories...'),
            'lap',
        );

        await waitFor(() => expect(draggable()).toHaveLength(0));
        expect(screen.queryByTitle('Drag to reorder')).toBeNull();
    });

    /*
     * The arrows go with them — same reason, and it is the same move. Left on
     * screen but disabled: they are removed from nothing else, and a button
     * that is still there and still looks live moves nothing when pressed.
     *
     * Searched for something two roots match, deliberately. Filtering down to
     * one leaves it both first and last on the list it is now on, so its
     * arrows are disabled by position anyway and the assertion would hold
     * whether or not filtering disables anything.
     */
    it('disables the arrows once a search filters them', async () => {
        const user = userEvent.setup();
        render(<Categories categories={categories} parentOptions={[]} />);

        expect(screen.getByLabelText('Move Component down').disabled).toBe(
            false,
        );

        /* Component and Monitor, not Laptop. */
        await user.type(
            screen.getByPlaceholderText('Search categories...'),
            'on',
        );

        await waitFor(() =>
            expect(screen.getByLabelText('Move Component down').disabled).toBe(
                true,
            ),
        );
        expect(screen.queryByLabelText('Move Laptop down')).toBeNull();
        expect(screen.getByLabelText('Move Monitor up').disabled).toBe(true);
    });

    it('gives the tree back when the search is cleared', async () => {
        const user = userEvent.setup();
        const input = screen.queryByPlaceholderText('Search categories...');
        expect(input).toBeNull();

        render(<Categories categories={categories} parentOptions={[]} />);
        const search = screen.getByPlaceholderText('Search categories...');

        await user.type(search, 'lap');
        await waitFor(() => expect(draggable()).toHaveLength(0));

        await user.clear(search);
        await waitFor(() => expect(draggable().length).toBeGreaterThan(0));
    });
});
