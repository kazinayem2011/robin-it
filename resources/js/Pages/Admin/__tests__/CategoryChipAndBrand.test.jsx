import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn() },
    usePage: () => ({ props: {}, url: '/admin/categories' }),
}));

const { CategoryChip } = await import('../Components/CategoryChip');
const { CategoryFormModal } = await import('../Components/CategoryFormModal');

/**
 * Editing a maker's shelf, and being told when one claims the wrong brand.
 *
 * A level-3 shelf is drawn as a small chip because there are dozens under one
 * parent. Small cost it the one thing it had to say: that it opens. The name
 * was a span with an onClick and a tooltip, beside a delete button — so the
 * only visible mark was the destructive one, and to a keyboard it was text.
 *
 * Somebody looking for "edit SteelSeries" therefore edited the card holding
 * it, and set the Headphone shelf to stand for SteelSeries: a product type
 * claiming to be a maker, which is not a thing that can be true.
 */
describe('a level-3 shelf chip', () => {
    const child = { id: 1341, name: 'SteelSeries', slug: 'steelseries' };

    it('is a button, so it can be reached and announced', () => {
        render(
            <CategoryChip child={child} onEdit={vi.fn()} onDelete={vi.fn()} />,
        );

        expect(
            screen.getByRole('button', { name: 'Edit SteelSeries' }),
        ).toBeInTheDocument();
    });

    it('opens the shelf it names, not the card around it', async () => {
        const onEdit = vi.fn();
        const user = userEvent.setup();

        render(
            <CategoryChip child={child} onEdit={onEdit} onDelete={vi.fn()} />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Edit SteelSeries' }),
        );

        expect(onEdit).toHaveBeenCalledWith(child);
    });

    /* The destructive one must not be the only thing that looks like an action. */
    it('names the delete for the shelf it would remove', () => {
        render(
            <CategoryChip child={child} onEdit={vi.fn()} onDelete={vi.fn()} />,
        );

        expect(
            screen.getByRole('button', { name: 'Delete SteelSeries' }),
        ).toBeInTheDocument();
    });
});

describe('a shelf standing for a brand', () => {
    const BRANDS = [
        { id: 33, name: 'SteelSeries' },
        { id: 7, name: 'Asus' },
    ];

    const open = (values) => {
        render(
            <CategoryFormModal
                modalState={{
                    isOpen: true,
                    mode: 'edit',
                    category: { id: 1334 },
                }}
                onClose={vi.fn()}
                formik={{
                    values: {
                        name: '',
                        parent_id: '',
                        brand_id: '',
                        ...values,
                    },
                    handleChange: vi.fn(),
                    handleBlur: vi.fn(),
                    handleSubmit: vi.fn(),
                    setFieldValue: vi.fn(),
                    touched: {},
                    errors: {},
                }}
                parentOptions={[]}
                brandOptions={BRANDS}
            />,
        );
    };

    /* The exact mistake: a product type claiming to be a maker. */
    it('says so when the shelf name and the brand disagree', () => {
        open({ name: 'Headphone', brand_id: 33 });

        // By role, because the field's own label says "Stands for a Brand" too.
        const warning = screen.getByRole('status');

        expect(warning).toHaveTextContent(/This shelf is called/i);
        expect(warning).toHaveTextContent('Headphone');
        expect(warning).toHaveTextContent('SteelSeries');
    });

    it('says nothing when the two agree', () => {
        open({ name: 'SteelSeries', brand_id: 33 });

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('ignores case and spacing, which are not a disagreement', () => {
        open({ name: '  steelseries ', brand_id: 33 });

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('says nothing when the shelf stands for no brand', () => {
        open({ name: 'Headphone', brand_id: '' });

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    /*
     * A warning, not a rule. "ROG" standing for Asus is a shelf somebody may
     * well want, and three of the four level-2 brand shelves in the shop are
     * correct — so blocking would invent a constraint that does not exist.
     */
    it('does not stop the form being saved', () => {
        open({ name: 'ROG', brand_id: 7 });

        expect(screen.getByRole('status')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /save changes/i }),
        ).toBeEnabled();
    });
});
