import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

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

vi.mock('@/services', () => ({
    adminService: {
        createProduct: vi
            .fn()
            .mockResolvedValue({ id: 1, name: 'Panel Test Laptop' }),
        updateProduct: vi.fn().mockResolvedValue({}),
        getProducts: vi.fn().mockResolvedValue({ data: [], meta: {} }),
        searchCategories: vi.fn().mockResolvedValue([]),
        getBrands: vi.fn().mockResolvedValue([]),
        getCategoryAttributes: vi.fn().mockResolvedValue([]),
    },
    uploadService: { upload: vi.fn() },
}));

/* The heavy editors are their own components with their own tests. */
vi.mock('@/Components/RichTextEditor', () => ({
    default: ({ label }) => <div data-editor="rich">{label}</div>,
}));
vi.mock('@/Components/ImageGalleryEditor', () => ({
    default: ({ label }) => <div data-editor="gallery">{label}</div>,
}));
/* Stubbed but usable: the form cannot be saved without a category. */
vi.mock('@/Components/CategoryPicker', () => ({
    default: ({ label, onChange, value }) => (
        <div data-editor="category" data-value={String(value ?? '')}>
            {label}
            {/* Named after its own label: this component is on the form
                twice, once for the primary shelf and once for the others. */}
            <button type="button" onClick={() => onChange?.(7)}>
                {`pick ${label}`}
            </button>
        </div>
    ),
}));
vi.mock('../Components/VariantEditor', () => ({
    default: () => <div data-editor="variants">Options</div>,
}));
vi.mock('../Components/SpecificationEditor', () => ({
    default: () => <div data-editor="specs">Specifications</div>,
}));
vi.mock('../Components/AttributeEditor', () => ({
    default: () => <div data-editor="attributes">Attributes</div>,
}));
vi.mock('../Components/ProductDetailsModal', () => ({ default: () => null }));

const { adminService } = await import('@/services');
const { default: Products } = await import('../Products');

/**
 * Every field the product form has, on the panel it belongs to.
 *
 * The panels were made by cutting a six-hundred-line block into six and
 * reassembling it. That the file still parses and builds says nothing about
 * whether a field was dropped, duplicated, or left on the wrong panel — so
 * this walks all six and names what is on each.
 */
describe('the product form, panel by panel', () => {
    const TABS = [
        'Basics',
        'Price & stock',
        'Description',
        'Specs & filters',
        'Photos',
        'Publishing',
    ];

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

    const labelsOnPanel = () => {
        const panel = document.querySelector('.admin-product-tabpanel');

        return [...panel.querySelectorAll('label, [data-editor]')]
            .map((n) => n.textContent.trim())
            .filter(Boolean);
    };

    beforeEach(() => vi.clearAllMocks());

    it('offers all six panels', async () => {
        await open();

        for (const name of TABS) {
            expect(
                screen.getByRole('tab', { name: new RegExp(name, 'i') }),
            ).toBeTruthy();
        }
    });

    it('puts every field on exactly one panel', async () => {
        const user = await open();
        const seen = new Map();

        for (const name of TABS) {
            await user.click(
                screen.getByRole('tab', { name: new RegExp(name, 'i') }),
            );

            for (const label of labelsOnPanel()) {
                expect(
                    seen.has(label),
                    `"${label}" appears on both ${seen.get(label)} and ${name}`,
                ).toBe(false);
                seen.set(label, name);
            }
        }

        // eslint-disable-next-line no-console
        console.log(
            '\n' +
                TABS.map(
                    (t) =>
                        `  ${t}\n` +
                        [...seen.entries()]
                            .filter(([, on]) => on === t)
                            .map(([l]) => `     · ${l}`)
                            .join('\n'),
                ).join('\n'),
        );

        expect(seen.size).toBeGreaterThan(25);
    });

    /**
     * Filling one in and pressing Save.
     *
     * The three fields the form cannot do without now sit on two panels, so
     * this is also the check that a save gathers values from panels that are
     * not open — the whole form is mounted, only hidden.
     */
    it('creates a product from fields spread across panels', async () => {
        const user = await open();

        await user.type(
            screen.getByLabelText(/Product Title/i),
            'Panel Test Laptop',
        );
        await user.click(screen.getByRole('button', { name: 'pick Category' }));

        await user.click(screen.getByRole('tab', { name: /Price & stock/i }));
        await user.type(screen.getByLabelText(/Regular Price/i), '125000');

        await user.click(screen.getByRole('tab', { name: /Description/i }));
        await user.type(
            screen.getByLabelText(/Short Summary/i),
            'A machine for testing panels.',
        );

        await user.click(
            screen.getByRole('button', { name: 'Create Product' }),
        );

        await waitFor(() =>
            expect(adminService.createProduct).toHaveBeenCalled(),
        );

        const sent = adminService.createProduct.mock.calls[0][0];

        expect(sent.name).toBe('Panel Test Laptop');
        expect(Number(sent.price)).toBe(125000);
        expect(sent.category_id).toBe(7);
        expect(sent.short_description).toContain('testing panels');
    });

    /**
     * A refusal on a panel that is not open has to open it, or pressing Save
     * looks like it did nothing at all.
     */
    it('opens the panel holding the problem when a save is refused', async () => {
        const user = await open();

        await user.type(
            screen.getByLabelText(/Product Title/i),
            'No Price Here',
        );
        await user.click(screen.getByRole('button', { name: 'pick Category' }));

        // Basics is complete; the missing price is on the next panel.
        await user.click(
            screen.getByRole('button', { name: 'Create Product' }),
        );

        await waitFor(() =>
            expect(
                screen.getByRole('tab', { name: /Price & stock/i }),
            ).toHaveAttribute('aria-selected', 'true'),
        );

        expect(adminService.createProduct).not.toHaveBeenCalled();
    });

    /* The pre-order fields only exist once pre-ordering is allowed. */
    it('reveals the pre-order fields when pre-ordering is turned on', async () => {
        const user = await open();

        await user.click(screen.getByRole('tab', { name: /Price & stock/i }));
        expect(screen.queryByLabelText(/Pre-order limit/i)).toBeNull();

        await user.click(
            screen.getByLabelText(/Allow pre-order when out of stock/i),
        );

        expect(screen.getByLabelText(/Pre-order limit/i)).toBeTruthy();
    });
});
