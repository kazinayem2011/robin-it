import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import FormInput from '../FormInput';

/**
 * The hint under a field.
 *
 * Eight screens passed `helperText` and none of them showed it. It was not a
 * declared prop, so it fell through to ...props and was spread onto the
 * <input>, where React dropped it as an unknown attribute and warned in the
 * console. Every one of those hints had been written and never seen.
 */
describe('FormInput helper text', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the hint under the field', () => {
        render(
            <FormInput
                name="reorder_level"
                label="Reorder at"
                value=""
                onChange={() => {}}
                helperText="Flag this for reordering once stock falls to here."
            />,
        );

        expect(
            screen.getByText(
                'Flag this for reordering once stock falls to here.',
            ),
        ).toBeInTheDocument();
    });

    it('does not leak the hint onto the input element', () => {
        const warn = vi.spyOn(console, 'error').mockImplementation(() => {});

        render(
            <FormInput
                name="barcode"
                label="Barcode"
                value=""
                onChange={() => {}}
                helperText="The manufacturer's number."
            />,
        );

        expect(screen.getByLabelText(/Barcode/i)).not.toHaveAttribute(
            'helpertext',
        );
        // React's "unknown prop" warning goes through console.error.
        expect(warn).not.toHaveBeenCalled();
    });

    /**
     * Two lines of small print under one field is one too many, and the error
     * is the one that matters.
     */
    it('gives way to an error', () => {
        render(
            <FormInput
                name="barcode"
                label="Barcode"
                value=""
                onChange={() => {}}
                error="That barcode is already on something else."
                helperText="The manufacturer's number."
            />,
        );

        expect(
            screen.getByText('That barcode is already on something else.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText("The manufacturer's number."),
        ).not.toBeInTheDocument();
    });

    it('renders nothing extra when no hint is given', () => {
        const { container } = render(
            <FormInput name="name" label="Name" value="" onChange={() => {}} />,
        );

        expect(
            container.querySelector('.auth-field-hint'),
        ).not.toBeInTheDocument();
    });
});
