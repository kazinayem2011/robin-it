import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn() },
    usePage: () => ({ props: {}, url: '/admin/categories' }),
}));

const { CategoryFormModal } = await import('../Components/CategoryFormModal');

/**
 * Choosing where a shelf sits.
 *
 * It was a native select holding 252 shelves, which is scrolled rather than
 * read — and the names repeat, several being called Accessories, so finding
 * the right one meant counting down a list where the right answer looks like
 * three wrong ones.
 *
 * The list itself is the part that must not change. Only a level 1 or 2 shelf
 * may be a parent, because the tree is three deep and nothing on the server
 * refuses a deeper one — `parent_id` is validated as existing and nothing
 * more. Searching the whole catalogue here, the way the product form does,
 * would quietly allow a fourth level that no screen can draw.
 */
describe('the parent category field', () => {
    /*
     * Ten, because Select turns its search on above eight and the shop has 252
     * eligible parents — a fixture of three would be testing the short list
     * nobody has.
     */
    const PARENTS = [
        { id: 1, name: 'Laptop', level: 1 },
        { id: 2, name: 'Laptop > All Laptop', level: 2 },
        { id: 3, name: 'Desktop', level: 1 },
        { id: 4, name: 'Desktop > Gaming PC', level: 2 },
        { id: 5, name: 'Monitor', level: 1 },
        { id: 6, name: 'Component', level: 1 },
        { id: 7, name: 'Component > Processor', level: 2 },
        { id: 8, name: 'Component > Graphics Card', level: 2 },
        { id: 9, name: 'Phone', level: 1 },
        { id: 10, name: 'Phone > Accessories', level: 2 },
    ];

    const formik = (values = {}) => ({
        values: { name: '', parent_id: '', ...values },
        handleChange: vi.fn(),
        handleSubmit: vi.fn(),
        handleBlur: vi.fn(),
        setFieldValue: vi.fn(),
        touched: {},
        errors: {},
    });

    const open = (modalState = {}) => {
        render(
            <CategoryFormModal
                modalState={{ isOpen: true, mode: 'create', ...modalState }}
                onClose={vi.fn()}
                formik={formik()}
                parentOptions={PARENTS}
                brandOptions={[]}
            />,
        );

        return userEvent.setup();
    };

    const field = () =>
        screen.getByRole('combobox', { name: /parent category/i });

    /*
     * Scoped to this field's own list. The brand field beside it is a native
     * select and its <option> elements answer to the same role, so an unscoped
     * query returns both fields' choices mixed together — which is how the
     * first version of these tests came back holding "Not a brand shelf".
     */
    const choices = () =>
        within(screen.getByRole('listbox', { name: /parent category/i }))
            .getAllByRole('option')
            .map((option) => option.textContent.trim());

    const searchBox = () =>
        screen.getByRole('textbox', { name: /search shelves/i });

    it('can be searched rather than scrolled', async () => {
        const user = open();

        await user.click(field());

        // Labelled by its placeholder, which is what a text input inside a
        // listbox has to carry to be findable at all.
        expect(searchBox()).toBeInTheDocument();
    });

    it('narrows to what was typed', async () => {
        const user = open();

        await user.click(field());
        await user.type(searchBox(), 'all lap');

        expect(choices()).toEqual(['Laptop > All Laptop']);
    });

    /* Every shelf offered carries its parent, because the names repeat. */
    it('names each shelf by its ancestry', async () => {
        const user = open();

        await user.click(field());

        expect(choices()).toContain('Laptop > All Laptop');
    });

    it('offers the top of the tree as a choice', async () => {
        const user = open();

        await user.click(field());

        expect(choices()).toContain('None (Top-Level Root Category)');
    });

    /*
     * Editing. A category offered as its own parent is a loop the tree cannot
     * be drawn from, and nothing downstream checks for one.
     */
    it('will not offer a category as its own parent', async () => {
        const user = open({
            mode: 'edit',
            category: { id: 2, name: 'All Laptop' },
        });

        await user.click(field());

        expect(choices()).not.toContain('Laptop > All Laptop');
        expect(choices()).toContain('Laptop');
    });

    /*
     * The constraint this field carries alone: the options are the eligible
     * parents handed in, never a search of the whole catalogue.
     */
    it('offers only the shelves it was given', async () => {
        const user = open();

        await user.click(field());

        expect(choices().filter((c) => !c.startsWith('None'))).toEqual(
            PARENTS.map((p) => p.name),
        );
    });
});
