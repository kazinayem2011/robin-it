import React, { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import CouponScopePicker from '../CouponScopePicker';

/**
 * Choosing the products a coupon covers. Every active product was laid out
 * as a chip with no way to search, so one product meant scrolling the whole
 * catalogue.
 */
describe('the coupon product list', () => {
    const products = [
        { id: 1, name: 'iPhone 15 Pro Max' },
        { id: 2, name: 'Logitech G502 Mouse' },
        { id: 3, name: 'Lenovo IdeaPad Slim 3' },
    ];

    function Form() {
        const [values, setValues] = useState({
            scope: 'products',
            product_ids: [3],
            category_ids: [],
        });

        return (
            <CouponScopePicker
                formik={{
                    values,
                    setFieldValue: (f, v) =>
                        setValues((all) => ({ ...all, [f]: v })),
                }}
                products={products}
            />
        );
    }

    it('narrows to what is typed, keeping the chosen ones in view', async () => {
        const user = userEvent.setup();
        render(<Form />);

        await user.type(screen.getByPlaceholderText(/e\.g\. iPhone/), 'iphone');

        expect(screen.getByText('iPhone 15 Pro Max')).toBeInTheDocument();
        expect(screen.queryByText('Logitech G502 Mouse')).toBeNull();
        // Ticked already, so it stays.
        expect(screen.getByText('Lenovo IdeaPad Slim 3')).toBeInTheDocument();

        await user.click(screen.getByText('iPhone 15 Pro Max'));
        expect(
            screen.getByText('iPhone 15 Pro Max').closest('label'),
        ).toHaveClass('is-selected');
    });

    it('says when nothing matches', async () => {
        const user = userEvent.setup();
        render(<Form />);

        await user.click(screen.getByText('Lenovo IdeaPad Slim 3'));
        await user.type(screen.getByPlaceholderText(/e\.g\. iPhone/), 'zzz');

        expect(screen.getByText('Nothing matches “zzz”.')).toBeInTheDocument();
    });
});
