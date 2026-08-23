import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import VariantEditor from '../VariantEditor';

/**
 * The allocation guard on the option editor.
 *
 * Switching a product that already holds stock over to options has to say where
 * those units go, and the split must account for every one of them — the shop's
 * total cannot change just because the way it is filed did. The server refuses a
 * mismatch; this is the half that tells the admin before they hit save.
 */
describe('VariantEditor', () => {
    /** A minimal stand-in for the Formik bag the editor actually receives. */
    const makeFormik = (values = {}) => {
        const bag = {
            values: {
                has_variants: false,
                variant_attributes: [],
                variants: [],
                ...values,
            },
            setFieldValue: vi.fn((field, value) => {
                bag.values = { ...bag.values, [field]: value };
            }),
            handleChange: vi.fn(),
            handleBlur: vi.fn(),
            touched: {},
            errors: {},
        };

        return bag;
    };

    const variant = (name, openingStock) => ({
        key: `k-${name}`,
        id: null,
        options: { Capacity: name },
        sku: '',
        price: '',
        discount_price: '',
        opening_stock: openingStock,
        is_active: true,
        stock_quantity: 0,
    });

    const converting = (variants) =>
        render(
            <VariantEditor
                formik={makeFormik({
                    has_variants: true,
                    variant_attributes: ['Capacity'],
                    variants,
                })}
                // A single product that already holds 23 units.
                editingProduct={{
                    id: 5,
                    stock_quantity: 23,
                    has_variants: false,
                }}
            />,
        );

    it('reports the full shelf as unallocated before anything is entered', () => {
        converting([variant('16GB', ''), variant('32GB', '')]);

        expect(
            screen.getByText(/23 of 23 unit\(s\) still to allocate/i),
        ).toBeInTheDocument();
    });

    it('reports the remainder as units are assigned', () => {
        converting([variant('16GB', 10), variant('32GB', '')]);

        expect(
            screen.getByText(/13 of 23 unit\(s\) still to allocate/i),
        ).toBeInTheDocument();
    });

    /** The state the server will accept: every unit accounted for. */
    it('confirms when the split covers the shelf exactly', () => {
        converting([variant('16GB', 10), variant('32GB', 13)]);

        expect(
            screen.getByText(/All 23 unit\(s\) accounted for/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/still to allocate/i),
        ).not.toBeInTheDocument();
    });

    /** Over-allocating would create stock that does not exist. */
    it('flags an over-allocation rather than letting it through', () => {
        converting([variant('16GB', 20), variant('32GB', 10)]);

        expect(
            screen.getByText(
                /7 unit\(s\) over — you have allocated 30 but only 23 are in stock/i,
            ),
        ).toBeInTheDocument();
    });

    it('treats a blank allocation as zero, not as NaN', () => {
        converting([variant('16GB', ''), variant('32GB', 23)]);

        expect(
            screen.getByText(/All 23 unit\(s\) accounted for/i),
        ).toBeInTheDocument();
    });

    /**
     * Editing an existing variant product is not a conversion: its stock is
     * already where it belongs, so there is nothing to split and no units field.
     */
    it('does not ask for an allocation when the product already uses options', () => {
        render(
            <VariantEditor
                formik={makeFormik({
                    has_variants: true,
                    variant_attributes: ['Capacity'],
                    variants: [
                        { ...variant('16GB', ''), id: 1, stock_quantity: 6 },
                    ],
                })}
                editingProduct={{
                    id: 5,
                    stock_quantity: 6,
                    has_variants: true,
                }}
            />,
        );

        expect(screen.queryByText(/to allocate/i)).not.toBeInTheDocument();
        // Stock is shown, never editable — editing an option must not move units.
        expect(screen.getByText(/6 on hand/i)).toBeInTheDocument();
    });

    it('warns before collapsing options back into one pool', () => {
        render(
            <VariantEditor
                formik={makeFormik({ has_variants: false })}
                editingProduct={{
                    id: 5,
                    stock_quantity: 12,
                    has_variants: true,
                }}
            />,
        );

        expect(
            screen.getByText(/collapse the options back into one stock pool/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/the total does not change/i),
        ).toBeInTheDocument();
    });

    it('offers no option fields at all until the product is switched over', () => {
        render(
            <VariantEditor
                formik={makeFormik()}
                editingProduct={{
                    id: 5,
                    stock_quantity: 23,
                    has_variants: false,
                }}
            />,
        );

        expect(
            screen.queryByLabelText(/Option names/i),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/to allocate/i)).not.toBeInTheDocument();
    });

    it('seeds a first option when the product is switched to variants', async () => {
        const formik = makeFormik();

        render(
            <VariantEditor
                formik={formik}
                editingProduct={{
                    id: 5,
                    stock_quantity: 23,
                    has_variants: false,
                }}
            />,
        );

        await userEvent.click(screen.getByRole('checkbox'));

        expect(formik.setFieldValue).toHaveBeenCalledWith('has_variants', true);
        expect(formik.values.variants).toHaveLength(1);
        expect(formik.values.variant_attributes).toEqual(['Option']);
    });
});
