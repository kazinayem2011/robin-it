import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const drawn = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn() },
    usePage: () => ({ props: {}, url: '/admin/categories' }),
}));
vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('@/Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('@/services', () => ({
    adminService: {
        createCategory: vi.fn(),
        updateCategory: vi.fn(),
        deleteCategory: vi.fn(),
        moveCategory: vi.fn(),
        moveCategoryTo: vi.fn(),
    },
}));

/*
 * The real chip, with a counter around it. Counting inside the mock is the
 * only way to see what the page does to the tree — the cost being measured is
 * renders, and nothing else reports them.
 */
vi.mock('../Components/CategoryChip', async () => {
    const actual = await vi.importActual('../Components/CategoryChip');

    return {
        ...actual,
        CategoryChip: (props) => {
            drawn(props.child.id);

            return <actual.CategoryChip {...props} />;
        },
        default: (props) => {
            drawn(props.child.id);

            return <actual.CategoryChip {...props} />;
        },
    };
});

const { default: Categories } = await import('../Categories');

/**
 * What the category tree draws, and when.
 *
 * Two costs, and they compound. The whole tree is 15 roots, 237 shelves and
 * 1,138 maker chips; every piece of state the page owns lives above all of
 * them, so each chip was drawn on load whether or not anyone had asked to see
 * its root, and then drawn again to show a modal, again to take it away, and
 * again on every character typed into it.
 *
 * That is what made the screen feel slow — not the network, which a modal
 * does not touch at all.
 *
 * Renders are countable in jsdom, unlike layout or paint, so this measures
 * the thing itself rather than asserting that memo was applied.
 */
describe('the category tree', () => {
    /* Two roots, so one can stay shut while the other is opened. */
    const categories = [
        {
            id: 1,
            name: 'Accessories',
            slug: 'accessories',
            is_active: true,
            children: [
                {
                    id: 10,
                    name: 'Headphone',
                    slug: 'accessories-headphone',
                    is_active: true,
                    children: [
                        { id: 100, name: 'SteelSeries', slug: 'a' },
                        { id: 101, name: 'Logitech', slug: 'b' },
                        { id: 102, name: 'Sony', slug: 'c' },
                    ],
                },
            ],
        },
        {
            id: 2,
            name: 'Laptop',
            slug: 'laptop',
            is_active: true,
            children: [
                {
                    id: 20,
                    name: 'Gaming Laptop',
                    slug: 'laptop-gaming',
                    is_active: true,
                    children: [{ id: 200, name: 'Razer', slug: 'd' }],
                },
            ],
        },
    ];

    const open = () => {
        render(
            <Categories
                categories={categories}
                parentOptions={[{ id: 1, name: 'Accessories', level: 1 }]}
                brandOptions={[{ id: 33, name: 'SteelSeries' }]}
            />,
        );

        return userEvent.setup();
    };

    /* The chevron beside a root's name. */
    const expandRoot = async (user, name) => {
        await user.click(
            screen.getByRole('button', { name: `Expand ${name}` }),
        );
        await screen.findByRole('button', { name: 'Edit SteelSeries' });
    };

    beforeEach(() => {
        vi.clearAllMocks();
        drawn.mockClear();
    });

    /*
     * A load. Nothing below a root is built until someone asks for that root,
     * which on the real tree is 15 cards rather than 1,390 cards and chips.
     */
    it('draws no chip until a root is opened', () => {
        open();

        expect(drawn).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Edit SteelSeries' }),
        ).toBeNull();
    });

    it('draws a root’s chips once it is opened', async () => {
        const user = open();

        await expandRoot(user, 'Accessories');

        expect(drawn.mock.calls.map(([id]) => id)).toEqual([100, 101, 102]);
    });

    /* Opening one root leaves the rest shut. */
    it('opens only the root that was clicked', async () => {
        const user = open();

        await expandRoot(user, 'Accessories');

        expect(screen.queryByRole('button', { name: 'Edit Razer' })).toBeNull();
    });

    /*
     * Searching. A shut root that matched would otherwise answer a search by
     * showing its own name and hiding the thing that was searched for.
     */
    it('shows what a search matched inside a shut root', async () => {
        const user = open();

        await user.type(
            screen.getByPlaceholderText(/search categories/i),
            'Razer',
        );

        expect(
            await screen.findByRole('button', { name: 'Edit Razer' }),
        ).toBeInTheDocument();
    });

    it('shuts the roots again when the search is cleared', async () => {
        const user = open();
        const box = screen.getByPlaceholderText(/search categories/i);

        await user.type(box, 'Razer');
        await screen.findByRole('button', { name: 'Edit Razer' });
        await user.clear(box);

        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Edit Razer' }),
            ).toBeNull(),
        );
    });

    /* The point of the memoisation. */
    it('redraws no chip when a dialog opens', async () => {
        const user = open();

        await expandRoot(user, 'Accessories');
        drawn.mockClear();

        await user.click(
            screen.getByRole('button', { name: 'Edit SteelSeries' }),
        );

        expect(await screen.findByText(/Edit Category/i)).toBeInTheDocument();
        expect(drawn).not.toHaveBeenCalled();
    });

    /*
     * And not on each keystroke either, which is the same fault repeated once
     * per character — the worst of it, since a name is a dozen of them.
     */
    it('redraws no chip while the dialog is typed into', async () => {
        const user = open();

        await expandRoot(user, 'Accessories');
        await user.click(
            screen.getByRole('button', { name: 'Edit SteelSeries' }),
        );
        await screen.findByText(/Edit Category/i);

        drawn.mockClear();
        await user.type(screen.getByLabelText(/Category Name/i), 'Steel');

        expect(drawn).not.toHaveBeenCalled();
    });

    it('redraws no chip when the dialog closes again', async () => {
        const user = open();

        await expandRoot(user, 'Accessories');
        await user.click(
            screen.getByRole('button', { name: 'Edit SteelSeries' }),
        );
        await screen.findByText(/Edit Category/i);

        drawn.mockClear();
        await user.click(screen.getAllByRole('button', { name: /cancel/i })[0]);

        expect(drawn).not.toHaveBeenCalled();
    });
});
