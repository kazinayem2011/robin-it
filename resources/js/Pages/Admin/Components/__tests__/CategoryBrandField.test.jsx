import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import { CategoryFormModal } from '../CategoryFormModal';

/**
 * Saying a shelf is a brand, from the shelf.
 *
 * 453 shelf names read like makers that `brands` has never heard of, because
 * saying so meant leaving this screen for the brands one and coming back. The
 * field offers it here instead — and has to keep two values in step, since a
 * <select> can only carry one: which brand, or that a new one is wanted.
 */
describe('the brand field on a category', () => {
    const BRANDS = [
        { id: 7, name: 'ASUS' },
        { id: 9, name: 'Lenovo' },
    ];

    const draw = (values = {}) => {
        const setFieldValue = vi.fn();

        const formik = {
            values: {
                name: 'Acer',
                slug: '',
                parent_id: '',
                brand_id: '',
                create_brand: false,
                icon: '',
                badge: '',
                is_offer: false,
                is_active: true,
                ...values,
            },
            errors: {},
            touched: {},
            handleChange: vi.fn(),
            handleBlur: vi.fn(),
            handleSubmit: vi.fn((e) => e?.preventDefault?.()),
            setFieldValue,
        };

        render(
            <CategoryFormModal
                modalState={{ isOpen: true, mode: 'edit', defaultLevel: 3 }}
                onClose={() => {}}
                formik={formik}
                parentOptions={[]}
                brandOptions={BRANDS}
            />,
        );

        return {
            setFieldValue,
            select: screen.getByRole('combobox', {
                name: /Stands for a Brand/i,
            }),
        };
    };

    /*
     * The field is the custom Select, not a native one, so its options exist
     * only while the panel is open and are chosen by clicking rather than by
     * `selectOptions`. What it shows when closed is the chosen option's label;
     * the value behind it is what `setFieldValue` is asked for.
     */
    const openList = async (user) => {
        await user.click(
            screen.getByRole('combobox', { name: /Stands for a Brand/i }),
        );

        return screen.getByRole('listbox');
    };

    const choose = async (user, name) => {
        await openList(user);
        await user.click(screen.getByRole('option', { name }));
    };

    it('offers to mint a brand named after the shelf', async () => {
        const user = userEvent.setup();
        draw();
        await openList(user);

        expect(
            screen.getByRole('option', {
                name: /Create “Acer” as a new brand/,
            }),
        ).toBeTruthy();
    });

    it('offers the brands that already exist', async () => {
        const user = userEvent.setup();
        draw();
        await openList(user);

        expect(screen.getByRole('option', { name: 'ASUS' })).toBeTruthy();
        expect(screen.getByRole('option', { name: 'Lenovo' })).toBeTruthy();
    });

    /* Nothing to name it after yet. */
    it('does not offer to mint one before the shelf has a name', async () => {
        const user = userEvent.setup();
        draw({ name: '   ' });
        await openList(user);

        expect(
            screen.queryByRole('option', { name: /as a new brand/ }),
        ).toBeNull();
    });

    /**
     * The two fields move together. Leaving brand_id set while asking for a
     * new brand would have the server link the old one and ignore the request.
     */
    it('asking for a new brand clears the chosen one', async () => {
        const user = userEvent.setup();
        const { setFieldValue } = draw({ brand_id: 9 });

        await choose(user, /as a new brand/);

        expect(setFieldValue).toHaveBeenCalledWith('create_brand', true);
        expect(setFieldValue).toHaveBeenCalledWith('brand_id', '');
    });

    it('choosing an existing brand cancels the request for a new one', async () => {
        const user = userEvent.setup();
        const { setFieldValue } = draw({ create_brand: true });

        await choose(user, 'ASUS');

        expect(setFieldValue).toHaveBeenCalledWith('create_brand', false);
        /*
         * The id as it was given, not a string of it. A native select coerced
         * every value to text; this control hands back what the option holds,
         * and `brand_id` is a number on the way in and on the way out.
         */
        expect(setFieldValue).toHaveBeenCalledWith('brand_id', 7);
    });

    it('“not a brand shelf” clears both', async () => {
        const user = userEvent.setup();
        const { setFieldValue } = draw({ brand_id: 7 });

        await choose(user, 'Not a brand shelf');

        expect(setFieldValue).toHaveBeenCalledWith('create_brand', false);
        expect(setFieldValue).toHaveBeenCalledWith('brand_id', '');
    });

    /* An edited shelf shows the brand it already stands for. */
    it('shows the brand a shelf already has', () => {
        const { select } = draw({ brand_id: 9 });

        expect(select).toHaveTextContent('Lenovo');
    });

    it('shows the mint option as chosen while it is being asked for', () => {
        const { select } = draw({ create_brand: true });

        expect(select).toHaveTextContent(/as a new brand/);
    });
});
