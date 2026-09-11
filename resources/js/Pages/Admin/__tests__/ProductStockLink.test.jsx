import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const visit = vi.fn();
const httpGet = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    // Rendered as a real anchor so a navigating Link is indistinguishable from
    // a plain one in the DOM — which is the point: the test has to catch the
    // navigation, not the component that performs it.
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn(), visit },
    usePage: () => ({ props: {}, url: '/admin/products' }),
}));
vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('@/Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));
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
        createProduct: vi.fn(),
        updateProduct: vi.fn(),
        getProducts: vi.fn().mockResolvedValue({ data: [], meta: {} }),
        getCategoryAttributes: vi.fn().mockResolvedValue([]),
    },
    uploadService: { upload: vi.fn() },
}));
vi.mock('@/Components/RichTextEditor', () => ({
    default: ({ label }) => <div>{label}</div>,
}));
vi.mock('@/Components/ImageGalleryEditor', () => ({
    default: ({ label }) => <div>{label}</div>,
}));
vi.mock('../Components/ProductDetailsModal', () => ({ default: () => null }));

const { default: Products } = await import('../Products');

/**
 * Leaving the product form without losing it.
 *
 * The modal guards its own close: a dirty form asks "Discard this product?"
 * before it goes. The Stock panel's "Receive stock" link went straight past
 * that — an Inertia Link following in the same tab, which tore the modal down
 * and took every filled field with it. Reported from the form itself, halfway
 * through entering a laptop.
 *
 * Worse on a product that has not been saved yet: there is nothing to receive
 * stock against, so the link could only ever lose work, and the hint beside it
 * was already saying to save first.
 */
describe('the Stock panel on the product form', () => {
    const PRODUCT = {
        id: 42,
        name: 'ASUS TUF Gaming A15',
        stock_quantity: 7,
        has_variants: false,
        variants: [],
        price: 145000,
        category_id: 411,
        is_active: true,
    };

    beforeEach(() => {
        vi.clearAllMocks();
        httpGet.mockResolvedValue({ data: [] });
    });

    const openCreate = async () => {
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

    const openEdit = async () => {
        const user = userEvent.setup();
        render(
            <Products
                products={{ data: [PRODUCT], meta: {} }}
                categories={[]}
                brands={[]}
            />,
        );
        await user.click(
            (await screen.findAllByRole('button', { name: /edit/i }))[0],
        );
        return user;
    };

    /* The Stock panel lives on the second tab; the modal opens on the first. */
    const priceTab = async (user) =>
        user.click(screen.getByRole('tab', { name: /price & stock/i }));

    it('offers no stock link at all on a product that does not exist yet', async () => {
        const user = await openCreate();
        await priceTab(user);

        expect(screen.getByText('None yet')).toBeInTheDocument();

        /*
         * Asked of the panel, not of a label. The link that lost the form read
         * "Receive stock" here and "Receive or adjust" on a saved product, so
         * matching either wording would have let the other back in.
         */
        expect(document.querySelector('.admin-stock-readonly-link')).toBeNull();
    });

    it('tells you to save first rather than offering a way to lose the form', async () => {
        await priceTab(await openCreate());

        expect(screen.getByText(/Save the product first/i)).toBeInTheDocument();
    });

    /**
     * A new tab is the whole fix: the form stays mounted in this one, so there
     * is nothing to discard and no dialog to answer.
     */
    it('opens the stock screen in a new tab on a saved product', async () => {
        await priceTab(await openEdit());

        const link = await screen.findByRole('link', {
            name: /receive or adjust/i,
        });

        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute(
            'rel',
            expect.stringContaining('noopener'),
        );
    });

    /** Landing on the stock screen with 1,300 products and no filter is a search. */
    it('deep-links to the product it was opened from', async () => {
        await priceTab(await openEdit());

        const link = await screen.findByRole('link', {
            name: /receive or adjust/i,
        });

        expect(link.getAttribute('href')).toContain('/admin/stock');
        expect(link.getAttribute('href')).toContain(
            encodeURIComponent('ASUS TUF Gaming A15'),
        );
    });

    /**
     * The guard the link used to bypass. Kept here beside it so the two are
     * read together: closing asks, and leaving no longer needs to.
     */
    it('still asks before discarding a dirty form on close', async () => {
        const user = await openCreate();

        fireEvent.change(screen.getByLabelText(/Product Title/i), {
            target: { value: 'Half-typed laptop' },
        });

        await user.click(screen.getAllByRole('button', { name: /close/i })[0]);

        expect(
            await screen.findByText(/Discard this product\?/i),
        ).toBeInTheDocument();
    });
});
