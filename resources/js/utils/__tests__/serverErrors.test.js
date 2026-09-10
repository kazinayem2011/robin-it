import { describe, it, expect, vi } from 'vitest';
import { applyServerErrors, toFormikPath } from '../serverErrors';

const form = () => ({
    setFieldError: vi.fn(),
    setFieldTouched: vi.fn(),
});

describe('toFormikPath', () => {
    it('rewrites a numeric segment the way formik reads it', () => {
        expect(toFormikPath('variants.1.sku')).toBe('variants[1].sku');
    });

    it('leaves a plain field alone', () => {
        expect(toFormikPath('name')).toBe('name');
    });

    it('handles an index at the end', () => {
        expect(toFormikPath('category_ids.2')).toBe('category_ids[2]');
    });

    /* A field whose own name contains a number is not an index. */
    it('does not rewrite a number inside a name', () => {
        expect(toFormikPath('meta_title2')).toBe('meta_title2');
    });
});

describe('applyServerErrors', () => {
    it('marks the field the server named', () => {
        const f = form();

        applyServerErrors(f, {
            errors: { name: ['Product title is required'] },
        });

        expect(f.setFieldError).toHaveBeenCalledWith(
            'name',
            'Product title is required',
        );
    });

    it('reaches a field inside a repeated row', () => {
        const f = form();

        applyServerErrors(f, {
            errors: {
                'variants.1.sku': [
                    'Stock code X is on more than one option here.',
                ],
            },
        });

        expect(f.setFieldError).toHaveBeenCalledWith(
            'variants[1].sku',
            'Stock code X is on more than one option here.',
        );
    });

    /*
     * A field the person never reached is the one they most need pointing at,
     * and formik shows an error only once the field is touched.
     */
    it('touches the field so the error is visible', () => {
        const f = form();

        applyServerErrors(f, { errors: { price: ['Price is required'] } });

        expect(f.setFieldTouched).toHaveBeenCalledWith('price', true, false);
    });

    /*
     * Without the false, yup runs at once and replaces what the server said —
     * so a rule the browser cannot check disappears as it is reported.
     */
    it('never asks for revalidation while doing it', () => {
        const f = form();

        applyServerErrors(f, { errors: { sku: ['Taken'] } });

        for (const call of f.setFieldTouched.mock.calls) {
            expect(call[2]).toBe(false);
        }
    });

    it('takes the first message when there are several', () => {
        const f = form();

        applyServerErrors(f, { errors: { name: ['Too short', 'Also rude'] } });

        expect(f.setFieldError).toHaveBeenCalledWith('name', 'Too short');
    });

    it('counts what it marked', () => {
        const f = form();

        const marked = applyServerErrors(f, {
            errors: { name: ['a'], price: ['b'], 'variants.0.sku': ['c'] },
        });

        expect(marked).toBe(3);
    });

    /* A failure that is not about fields — a timeout, a 500 — marks nothing. */
    it('does nothing for an error with no field map', () => {
        const f = form();

        expect(applyServerErrors(f, { message: 'Network down' })).toBe(0);
        expect(applyServerErrors(f, null)).toBe(0);
        expect(f.setFieldError).not.toHaveBeenCalled();
    });

    it('skips a field with an empty message', () => {
        const f = form();

        expect(applyServerErrors(f, { errors: { name: [] } })).toBe(0);
    });
});
