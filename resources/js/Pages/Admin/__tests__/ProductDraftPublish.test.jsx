import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const createProduct = vi.fn();
const updateProduct = vi.fn();
const getCategoryAttributes = vi.fn();
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
        updateProduct,
        getProducts: vi.fn().mockResolvedValue({ data: [], meta: {} }),
        getCategoryAttributes,
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
 * Creating a product without publishing it by accident.
 *
 * "Create Product" used to mean "publish": is_active defaulted to true, and
 * the form encourages saving early — stock cannot be received against a
 * product that does not exist yet. So a product entered across six tabs went
 * live the moment the first tab was filled, with no photograph, no spec sheet
 * and no filter answers.
 *
 * That is worse than not being listed. Answering no filters makes it invisible
 * to the sidebar, so the only shoppers who reach it are the ones who searched
 * its exact name — and what they find is a placeholder image.
 */
describe('publishing a new product', () => {
    const CATEGORIES = [{ id: 411, name: 'Gaming Laptop', path: 'Laptop' }];

    const FILTERS = [
        {
            id: 18,
            name: 'Display Type',
            slug: 'display-type',
            input_type: 'enum',
            values: [
                { id: 1, label: 'LED' },
                { id: 2, label: 'OLED' },
            ],
        },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
        createProduct.mockResolvedValue({ id: 1 });
        updateProduct.mockResolvedValue({ id: 1 });
        getCategoryAttributes.mockResolvedValue(FILTERS);

        /*
         * The category picker and the attribute editor both go through
         * axiosInstance, and want different shapes back — answering one shape
         * to both leaves the picker showing nothing to click.
         */
        httpGet.mockImplementation((url = '') =>
            Promise.resolve({
                data: String(url).includes('/attributes')
                    ? FILTERS
                    : CATEGORIES,
            }),
        );
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

    const goTo = (user, name) =>
        user.click(screen.getByRole('tab', { name: new RegExp(name, 'i') }));

    it('starts a new product as a draft, not live', async () => {
        const user = await openCreate();
        await goTo(user, 'publishing');

        expect(
            screen.getByLabelText(/Active in Live Storefront/i),
        ).not.toBeChecked();
    });

    it('says what leaving it unticked means', async () => {
        const user = await openCreate();
        await goTo(user, 'publishing');

        expect(screen.getByText(/save a draft/i)).toBeInTheDocument();
    });

    /*
     * The point of the default: a half-filled product can be saved for later
     * without anybody seeing it, and with no dialog to answer on the way.
     */
    /*
     * Opening the form and thinking better of it should cost nothing.
     *
     * `dirty` is measured against whatever resetForm was handed, not against
     * the initialValues literal, so changing the published default cannot make
     * a fresh form read as edited — but the two are worth keeping in step
     * anyway, and this is the behaviour that would break first if they ever
     * stopped being.
     */
    it('opens clean, so closing an untouched form asks nothing', async () => {
        const user = await openCreate();

        await user.click(screen.getAllByRole('button', { name: /close/i })[0]);

        expect(
            screen.queryByText(/Discard this product\?/i),
        ).not.toBeInTheDocument();
    });

    /** Enough to pass validation; the gaps are the point. */
    const fillMinimum = async (user) => {
        fireEvent.change(screen.getByLabelText(/Product Title/i), {
            target: { value: 'ASUS TUF Gaming A15' },
        });

        // Required, and it is also what decides which filters are offered.
        const picker = screen.getByLabelText(/^Category/i);
        fireEvent.focus(picker);
        fireEvent.change(picker, { target: { value: 'lap' } });
        fireEvent.click(
            await screen.findByText('Gaming Laptop', {}, { timeout: 5000 }),
        );

        await goTo(user, 'price');
        fireEvent.change(screen.getByLabelText(/Regular Price/i), {
            target: { value: '145000' },
        });
    };

    const publish = async (user) => {
        await goTo(user, 'publishing');
        await user.click(screen.getByLabelText(/Active in Live Storefront/i));
        await user.click(screen.getByRole('button', { name: /create/i }));
    };

    it('asks before publishing something with no photograph', async () => {
        const user = await openCreate();
        await fillMinimum(user);
        await publish(user);

        expect(
            await screen.findByText(/Publish it like this\?/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/no photograph/i)).toBeInTheDocument();
        expect(createProduct).not.toHaveBeenCalled();
    });

    /*
     * The reason that matters most, and the one nothing else in the admin
     * would ever mention: a product answering no filters cannot be reached by
     * anybody narrowing the category down.
     */
    it('names the filters it will be invisible to', async () => {
        const user = await openCreate();
        await fillMinimum(user);

        // Open the panel so the shelf's questions are loaded and counted.
        await goTo(user, 'specs');
        await waitFor(() => expect(httpGet).toHaveBeenCalled());

        await publish(user);

        expect(
            await screen.findByText(/no filter answers/i),
        ).toBeInTheDocument();
    });

    it('goes ahead when told to publish anyway', async () => {
        const user = await openCreate();
        await fillMinimum(user);
        await publish(user);

        await user.click(
            await screen.findByRole('button', { name: /publish anyway/i }),
        );

        await waitFor(() => expect(createProduct).toHaveBeenCalled());
        expect(createProduct.mock.calls[0][0].is_active).toBe(true);
    });

    it('leaves the form alone when told to go back', async () => {
        const user = await openCreate();
        await fillMinimum(user);
        await publish(user);

        await user.click(
            await screen.findByRole('button', { name: /go back/i }),
        );

        expect(createProduct).not.toHaveBeenCalled();

        // Still open and still filled — nothing was discarded to ask the
        // question. Read from Basics, since a tab only renders its own panel.
        await goTo(user, 'basics');
        expect(screen.getByLabelText(/Product Title/i)).toHaveValue(
            'ASUS TUF Gaming A15',
        );
    });

    it('saves a draft with no questions asked', async () => {
        const user = await openCreate();

        fireEvent.change(screen.getByLabelText(/Product Title/i), {
            target: { value: 'Half-finished laptop' },
        });
        await goTo(user, 'price');
        fireEvent.change(screen.getByLabelText(/Regular Price/i), {
            target: { value: '145000' },
        });

        await user.click(screen.getByRole('button', { name: /create/i }));

        expect(
            screen.queryByText(/Publish it like this\?/i),
        ).not.toBeInTheDocument();
    });
});
