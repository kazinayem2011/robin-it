import React, { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import VariantEditor from '../VariantEditor';

/**
 * Typing the option names key by key. The box showed the parsed names joined
 * back up, so the comma and space after "Storage" vanished before the next
 * key and "Storage, Color" came out "StorageColor".
 */
describe('the option names box', () => {
    // A form that re-renders like Formik does, so every key is a new render.
    function Form({ onNames }) {
        const [values, setValues] = useState({
            has_variants: true,
            variant_attributes: ['Option'],
            variants: [{ key: 'a', id: null, options: {}, is_active: true }],
        });

        return (
            <VariantEditor
                formik={{
                    values,
                    touched: {},
                    errors: {},
                    setFieldValue: (field, value) => {
                        if (field === 'variant_attributes') onNames(value);
                        setValues((v) => ({ ...v, [field]: value }));
                    },
                }}
                editingProduct={null}
            />
        );
    }

    it('takes a comma and a second name', async () => {
        let names = [];
        const user = userEvent.setup();
        render(<Form onNames={(n) => (names = n)} />);

        const box = screen.getByDisplayValue('Option');
        await user.clear(box);
        await user.type(box, 'Storage, Color');

        expect(box).toHaveValue('Storage, Color');
        expect(names).toEqual(['Storage', 'Color']);
        // A field per name in the option row.
        expect(screen.getByText('Storage')).toBeInTheDocument();
        expect(screen.getByText('Color')).toBeInTheDocument();
    });
});
