import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import FormSelect from '../FormSelect';

/**
 * The hint under a dropdown.
 *
 * FormInput has had one for a while; FormSelect did not declare the prop, so a
 * hint passed to it fell through to ...props, was spread onto the <select>, and
 * React dropped it as an unknown attribute. The first screen to pass one — the
 * delivery form, saying that the opening-balance source is not a purchase —
 * printed nothing.
 */
describe('FormSelect helper text', () => {
    const options = [{ value: '1', label: 'Opening balance' }];

    it('shows the hint under the field', () => {
        render(
            <FormSelect
                name="supplier_id"
                label="Source"
                value=""
                onChange={() => {}}
                options={options}
                helperText="Recorded as an opening balance, not a purchase."
            />,
        );

        expect(
            screen.getByText('Recorded as an opening balance, not a purchase.'),
        ).toBeInTheDocument();
    });

    /** An error replaces it, rather than stacking two lines under one field. */
    it('shows the error instead when there is one', () => {
        render(
            <FormSelect
                name="supplier_id"
                label="Source"
                value=""
                onChange={() => {}}
                options={options}
                helperText="Recorded as an opening balance, not a purchase."
                error="Choose a source."
            />,
        );

        expect(screen.getByText('Choose a source.')).toBeInTheDocument();
        expect(
            screen.queryByText(
                'Recorded as an opening balance, not a purchase.',
            ),
        ).not.toBeInTheDocument();
    });

    it('renders nothing extra when no hint is given', () => {
        const { container } = render(
            <FormSelect
                name="supplier_id"
                label="Source"
                value=""
                onChange={() => {}}
                options={options}
            />,
        );

        expect(container.querySelector('.auth-field-hint')).toBeNull();
    });
});
