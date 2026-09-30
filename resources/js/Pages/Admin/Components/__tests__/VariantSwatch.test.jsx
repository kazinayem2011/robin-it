import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import VariantEditor, { guessSwatch } from '../VariantEditor';

/**
 * A colour option's swatch in the product form: guessed from the name, and
 * picked once per colour rather than once per row.
 */
describe('the colour swatch on an option', () => {
    const row = (key, storage, colour, swatch = '') => ({
        key,
        id: null,
        options: { 'RAM / Storage': storage, Color: colour },
        swatch,
        is_active: true,
        stock_quantity: 0,
    });

    const setup = (variants) => {
        const setFieldValue = vi.fn();

        render(
            <VariantEditor
                formik={{
                    values: {
                        has_variants: true,
                        variant_attributes: ['RAM / Storage', 'Color'],
                        variants,
                    },
                    setFieldValue,
                    touched: {},
                    errors: {},
                }}
                editingProduct={{ id: 1311, has_variants: true }}
            />,
        );

        // The rows as last written.
        return () =>
            setFieldValue.mock.calls
                .filter(([f]) => f === 'variants')
                .at(-1)[1];
    };

    it('offers a picker beside Color only', () => {
        setup([row('a', '512GB', '')]);

        expect(screen.getAllByLabelText('Color swatch')).toHaveLength(1);
        expect(screen.queryByLabelText('RAM / Storage swatch')).toBeNull();
    });

    it('fills the swatch in from a colour name', () => {
        const written = setup([row('a', '512GB', '')]);

        fireEvent.change(screen.getByPlaceholderText('e.g. Black'), {
            target: { value: 'Blue Titanium' },
        });

        expect(written()[0].swatch).toBe(guessSwatch('Blue Titanium'));
        expect(written()[0].swatch).toMatch(/^#[0-9a-f]{6}$/);
    });

    it('takes the swatch another row already has for that colour', () => {
        const written = setup([
            row('a', '512GB', 'Blue Titanium', '#123456'),
            row('b', '1TB', 'Blu'),
        ]);

        const second = screen.getByDisplayValue('Blu');
        fireEvent.change(second, { target: { value: 'blue titanium' } });

        expect(written()[1].swatch).toBe('#123456');
    });

    it('gives a picked colour to every row of that colour', () => {
        const written = setup([
            row('a', '512GB', 'Blue Titanium', '#3d5a80'),
            row('b', '1TB', 'Blue Titanium', '#3d5a80'),
            row('c', '512GB', 'Black Titanium', '#1d1d1f'),
        ]);

        fireEvent.change(screen.getAllByLabelText('Color swatch')[0], {
            target: { value: '#445566' },
        });

        expect(written().map((v) => v.swatch)).toEqual([
            '#445566',
            '#445566',
            '#1d1d1f',
        ]);
    });

    it('keeps a swatch picked by hand when the name is corrected', () => {
        const written = setup([
            row('a', '512GB', 'Natral Titanium', '#aabbcc'),
        ]);

        fireEvent.change(screen.getByDisplayValue('Natral Titanium'), {
            target: { value: 'Natural Titanium' },
        });

        expect(written()[0].swatch).toBe('#aabbcc');
    });
});
