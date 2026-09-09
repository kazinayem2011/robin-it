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
            select: screen.getByLabelText(/Stands for a Brand/i),
        };
    };

    it('offers to mint a brand named after the shelf', () => {
        draw();

        expect(
            screen.getByRole('option', {
                name: /Create “Acer” as a new brand/,
            }),
        ).toBeTruthy();
    });

    it('offers the brands that already exist', () => {
        draw();

        expect(screen.getByRole('option', { name: 'ASUS' })).toBeTruthy();
        expect(screen.getByRole('option', { name: 'Lenovo' })).toBeTruthy();
    });

    /* Nothing to name it after yet. */
    it('does not offer to mint one before the shelf has a name', () => {
        draw({ name: '   ' });

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
        const { setFieldValue, select } = draw({ brand_id: 9 });

        await user.selectOptions(select, 'new');

        expect(setFieldValue).toHaveBeenCalledWith('create_brand', true);
        expect(setFieldValue).toHaveBeenCalledWith('brand_id', '');
    });

    it('choosing an existing brand cancels the request for a new one', async () => {
        const user = userEvent.setup();
        const { setFieldValue, select } = draw({ create_brand: true });

        await user.selectOptions(select, '7');

        expect(setFieldValue).toHaveBeenCalledWith('create_brand', false);
        expect(setFieldValue).toHaveBeenCalledWith('brand_id', '7');
    });

    it('“not a brand shelf” clears both', async () => {
        const user = userEvent.setup();
        const { setFieldValue, select } = draw({ brand_id: 7 });

        await user.selectOptions(select, '');

        expect(setFieldValue).toHaveBeenCalledWith('create_brand', false);
        expect(setFieldValue).toHaveBeenCalledWith('brand_id', '');
    });

    /* An edited shelf shows the brand it already stands for. */
    it('shows the brand a shelf already has', () => {
        const { select } = draw({ brand_id: 9 });

        expect(select.value).toBe('9');
    });

    it('shows the mint option as chosen while it is being asked for', () => {
        const { select } = draw({ create_brand: true });

        expect(select.value).toBe('new');
    });
});
