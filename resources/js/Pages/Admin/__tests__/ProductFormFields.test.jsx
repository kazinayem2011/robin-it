import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const createProduct = vi.fn().mockResolvedValue({ id: 1 });
const httpGet = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: {}, url: '/admin/products' }),
}));
vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('@/Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

/* Only the network is stubbed. Every form control is the real one. */
vi.mock('../../../services/axiosInstance', () => ({
    default: {
        get: (...a) => httpGet(...a),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}));
vi.mock('@/services', () => ({
    adminService: {
        createProduct,
        updateProduct: vi.fn(),
        getProducts: vi.fn().mockResolvedValue({ data: [], meta: {} }),
        getCategoryAttributes: vi.fn().mockResolvedValue([]),
    },
    uploadService: { upload: vi.fn() },
}));

/* Needs a real editor surface; it has its own tests. */
vi.mock('@/Components/RichTextEditor', () => ({
    default: ({ label, value, onChange }) => (
        <label>
            {label}
            <textarea
                value={value ?? ''}
                onChange={(e) => onChange?.(e.target.value)}
            />
        </label>
    ),
}));
vi.mock('@/Components/ImageGalleryEditor', () => ({
    default: ({ label }) => <div>{label}</div>,
}));
vi.mock('../Components/ProductDetailsModal', () => ({ default: () => null }));

const { default: Products } = await import('../Products');

/**
 * Every field on the product form, driven for real.
 *
 * The last sweep mocked CategoryPicker away and so could not see that its
 * results were being clipped — the endpoint answered, the component worked in
 * isolation, and typing into "Also list under" still did nothing. So this
 * stubs the network and nothing else, fills the form, and reads the payload
 * that reaches the API.
 */
describe('the product form, field by field', () => {
    const CATEGORIES = [
        { id: 396, name: 'All Laptop', path: 'Laptop' },
        { id: 411, name: 'Gaming Laptop', path: 'Laptop' },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
        createProduct.mockResolvedValue({ id: 1 });
        httpGet.mockResolvedValue({ data: CATEGORIES });
    });

    const open = async () => {
        const user = userEvent.setup();
        render(
            <Products
                products={{ data: [], meta: {} }}
                categories={[]}
                brands={[]}
            />,
        );
        await user.click(
            await screen.findByRole('button', {
                name: /Add New Product|Add Product/i,
            }),
        );
        return user;
    };

    const panel = async (user, name) =>
        user.click(screen.getByRole('tab', { name: new RegExp(name, 'i') }));

    /*
     * Set a plain field. fireEvent rather than user.type, which sends an event
     * per character — with a dozen fields that is thousands of renders, and
     * this suite slowed enough to time out others running beside it.
     */
    const fill = (label, value) =>
        fireEvent.change(screen.getByLabelText(new RegExp(label, 'i')), {
            target: { value },
        });

    /** The picker is typed into for real: the search is what is being tested. */
    const pick = async (label, choice) => {
        const box = screen.getByLabelText(new RegExp(label, 'i'));

        fireEvent.focus(box);
        fireEvent.change(box, { target: { value: 'lap' } });

        fireEvent.click(await screen.findByText(choice, {}, { timeout: 5000 }));
    };

    it('reaches the API with what was typed on every panel', async () => {
        const user = await open();

        await user.type(
            screen.getByLabelText(/Product Title/i),
            'Field Sweep Laptop',
        );
        fill('^Model', 'FS-1');
        fill('MPN', 'FS-MPN-1');
        await pick('Category', 'Gaming Laptop');
        await pick('Also list under', 'All Laptop');

        await panel(user, 'Price & stock');
        fill('Regular Price', '99000');
        await user.type(
            screen.getByLabelText(/Special Discount Price/i),
            '92000',
        );
        fill('Barcode', 'FS-BAR-1');
        fill('Minimum Order Qty', '2');

        await panel(user, 'Description');
        fill('Short Summary', 'Short copy.');
        fill('Warranty \\(months\\)', '24');

        await panel(user, 'Publishing');
        await user.type(
            screen.getByLabelText(/Meta Title/i),
            'Field Sweep Title',
        );

        await user.click(
            screen.getByRole('button', { name: 'Create Product' }),
        );

        await waitFor(() => expect(createProduct).toHaveBeenCalled());

        const sent = createProduct.mock.calls[0][0];

        expect(sent.name).toBe('Field Sweep Laptop');
        expect(sent.model).toBe('FS-1');
        expect(sent.mpn).toBe('FS-MPN-1');
        expect(sent.category_id).toBe(411);
        expect(sent.category_ids).toEqual([396]);
        expect(Number(sent.price)).toBe(99000);
        expect(Number(sent.discount_price)).toBe(92000);
        expect(sent.barcode).toBe('FS-BAR-1');
        expect(Number(sent.min_order_quantity)).toBe(2);
        expect(sent.short_description).toBe('Short copy.');
        expect(Number(sent.warranty_months)).toBe(24);
        expect(sent.meta_title).toBe('Field Sweep Title');

        /*
         * Longer than the 5s default: this one mounts the whole page, opens
         * the dialog, drives two debounced typeaheads and fills four panels.
         * It finishes in about a second alone and was tipping over the default
         * only when the rest of the suite ran alongside it.
         */
    }, 20000);

    /* The field that was broken, driven through the whole form. */
    it('adds a second shelf and shows it as a chip', async () => {
        await open();

        await pick('Also list under', 'All Laptop');

        /*
         * The chip, specifically — the name is also still on screen in the
         * open suggestion list, so its remove button is what identifies it.
         */
        expect(
            screen.getByRole('button', { name: /Remove All Laptop/i }),
        ).toBeTruthy();
        expect(
            document.querySelector('.category-picker-chip').textContent,
        ).toContain('All Laptop');
    });
});
