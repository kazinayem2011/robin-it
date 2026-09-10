import { describe, it, expect, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }) => children,
    router: { reload: () => {} },
    usePage: () => ({ props: {}, url: '/admin/products' }),
}));
vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => children,
}));
vi.mock('@/services', () => ({ adminService: {}, productService: {} }));

const { problemsOn, firstTabWithProblem } = await import('../Products');

/**
 * Grouping a form hides fields, and a hidden field can be the one holding the
 * save up. These two functions are what stop that being a regression: every
 * tab counts its own problems, and a refused save opens the first one that
 * has any.
 */
describe('problemsOn', () => {
    it('counts only the fields that belong to the tab', () => {
        const errors = { name: 'required', price: 'too low' };

        expect(problemsOn('basics', errors)).toBe(1);
        expect(problemsOn('pricing', errors)).toBe(1);
        expect(problemsOn('photos', errors)).toBe(0);
    });

    it('counts several on one tab', () => {
        expect(
            problemsOn('pricing', {
                price: 'a',
                discount_price: 'b',
                barcode: 'c',
            }),
        ).toBe(3);
    });

    it('is nothing when the form is clean', () => {
        expect(problemsOn('basics', {})).toBe(0);
        expect(problemsOn('basics')).toBe(0);
    });

    /* A field on no tab is a field nobody can be sent to. */
    it('ignores a key it does not know', () => {
        expect(problemsOn('basics', { some_new_field: 'x' })).toBe(0);
    });

    it('does not invent a tab', () => {
        expect(problemsOn('nowhere', { name: 'x' })).toBe(0);
    });
});

describe('firstTabWithProblem', () => {
    /* In the order the tabs are shown, not the order the errors arrived. */
    it('picks the earliest tab that has one', () => {
        expect(firstTabWithProblem({ meta_title: 'x', price: 'y' })).toBe(
            'pricing',
        );
    });

    it('picks the very first when that is where it is', () => {
        expect(firstTabWithProblem({ name: 'required', images: 'x' })).toBe(
            'basics',
        );
    });

    it('reaches the last tab when only it has one', () => {
        expect(firstTabWithProblem({ meta_description: 'too long' })).toBe(
            'publishing',
        );
    });

    /* Nothing to jump to, so the reader stays where they are. */
    it('is null when the form is clean', () => {
        expect(firstTabWithProblem({})).toBeNull();
        expect(firstTabWithProblem()).toBeNull();
    });

    it('is null when the only complaint is about a field on no tab', () => {
        expect(firstTabWithProblem({ mystery: 'x' })).toBeNull();
    });

    /**
     * The nested one that started all this: a repeated stock code is reported
     * as variants.1.sku, and applyServerErrors marks `variants` as well, which
     * is what has to land on a tab.
     */
    it('sends a variant complaint to the price and stock tab', () => {
        expect(firstTabWithProblem({ variants: 'a code is repeated' })).toBe(
            'pricing',
        );
    });

    /**
     * And the way the server actually spells it. formik leaves `variants`
     * holding an array of row errors; Laravel names the row and the field
     * outright, so matching whole keys finds one and misses the other.
     */
    it('sends the server’s own spelling there too', () => {
        expect(
            firstTabWithProblem({
                'variants.1.sku': [
                    'Stock code TAKEN is already on another option.',
                ],
            }),
        ).toBe('pricing');
    });

    it('reads a formik-style index the same way', () => {
        expect(firstTabWithProblem({ 'variants[1].sku': 'taken' })).toBe(
            'pricing',
        );
    });

    it('counts one row of a repeated field once, not per key', () => {
        expect(
            problemsOn('pricing', {
                'variants.0.sku': ['a'],
                'variants.1.sku': ['b'],
            }),
        ).toBe(1);
    });
});
