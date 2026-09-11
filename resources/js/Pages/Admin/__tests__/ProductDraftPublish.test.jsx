import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const createProduct = vi.fn();
const updateProduct = vi.fn();
const duplicateProduct = vi.fn();
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
const toastSuccess = vi.fn();
const toastError = vi.fn();

vi.mock('@/Components/Toast', () => ({
    toast: { error: toastError, success: toastSuccess },
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
        duplicateProduct,
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
        duplicateProduct.mockResolvedValue({
            message: "Copied to 'Existing (Copy)', saved as a draft.",
            data: {
                id: 99,
                name: 'Existing (Copy)',
                price: 100,
                is_active: false,
            },
        });
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

    // ── taking one down from the list ────────────────────────────────

    const listing = (overrides = {}) => ({
        id: 9,
        name: 'Existing',
        price: 100,
        is_active: true,
        category_id: 411,
        has_variants: false,
        variants: [],
        ...overrides,
    });

    const renderList = async (product) => {
        const user = userEvent.setup();
        render(
            <Products
                products={{ data: [product], meta: {} }}
                categories={[]}
                brands={[]}
            />,
        );
        return user;
    };

    /*
     * Taking a product down was six clicks — open it, find the Publishing tab,
     * untick, save — for the action most likely to be wanted in a hurry, when
     * something is listed wrong and shoppers can see it.
     */
    it('takes a live product down from the list', async () => {
        const user = await renderList(listing());

        const toggle = await screen.findByRole('switch');
        expect(toggle).toBeChecked();

        await user.click(toggle);

        await waitFor(() => expect(updateProduct).toHaveBeenCalled());
        expect(updateProduct).toHaveBeenCalledWith(9, { is_active: false });
    });

    it('puts a hidden one back up the same way', async () => {
        const user = await renderList(listing({ is_active: false }));

        const toggle = await screen.findByRole('switch');
        expect(toggle).not.toBeChecked();

        await user.click(toggle);

        await waitFor(() => expect(updateProduct).toHaveBeenCalled());
        expect(updateProduct).toHaveBeenCalledWith(9, { is_active: true });
    });

    /*
     * A switch, so it announces itself as one and says which way it is. It was
     * a coloured word that happened to be clickable, which nobody would try:
     * the colour said "state" and nothing said "control".
     */
    it('is announced as a switch, not read as a label', async () => {
        await renderList(listing());

        const toggle = await screen.findByRole('switch');

        expect(toggle).toHaveAccessibleName(/active/i);
        expect(toggle).toHaveAttribute('aria-checked', 'true');
    });

    /* It decides what shoppers see, so the row waits rather than flipping back. */
    it('does not change the row when the save fails', async () => {
        updateProduct.mockRejectedValue({ message: 'Nope' });
        const user = await renderList(listing());

        await user.click(await screen.findByRole('switch'));

        await waitFor(() => expect(updateProduct).toHaveBeenCalled());
        expect(await screen.findByRole('switch')).toBeChecked();
    });

    // ── "Also list under" ────────────────────────────────────────────

    /* A product filed under two extra shelves, as the list sends it. */
    const withExtras = () =>
        listing({
            category_id: 411,
            categories: [
                {
                    id: 411,
                    name: 'Gaming Laptop',
                    parent: { id: 395, name: 'Laptop' },
                },
                {
                    id: 396,
                    name: 'All Laptop',
                    parent: { id: 395, name: 'Laptop' },
                },
                {
                    id: 500,
                    name: 'Access Control Accessories',
                    parent: {
                        id: 499,
                        name: 'Accessories',
                        parent: { id: 498, name: 'Security' },
                    },
                },
            ],
        });

    /**
     * These were never populated on edit: the state started empty and only
     * grew as somebody picked, so a product already listed under three shelves
     * opened showing none. The ids were loaded and saved correctly underneath,
     * so nothing was lost — it just could not be seen or taken off.
     */
    it('shows the shelves a product is already listed under', async () => {
        const user = await renderList(withExtras());
        await user.click(
            (await screen.findAllByRole('button', { name: /edit/i }))[0],
        );

        expect(
            screen.getByRole('button', { name: /Remove All Laptop/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: /Remove Access Control Accessories/i,
            }),
        ).toBeInTheDocument();
    });

    /* The primary has its own field above, and the server adds it back anyway. */
    it('leaves the primary shelf out of the extras', async () => {
        const user = await renderList(withExtras());
        await user.click(
            (await screen.findAllByRole('button', { name: /edit/i }))[0],
        );

        expect(
            screen.queryByRole('button', { name: /Remove Gaming Laptop/i }),
        ).not.toBeInTheDocument();
    });

    /* Several shelves are called Accessories; the name alone names none. */
    it('names each shelf by its ancestry', async () => {
        const user = await renderList(withExtras());
        await user.click(
            (await screen.findAllByRole('button', { name: /edit/i }))[0],
        );

        expect(
            screen.getByText(/Security › Accessories ›/),
        ).toBeInTheDocument();
    });

    /* They are not form values, so resetForm does not reach them. */
    it("does not carry one product's shelves into the next form", async () => {
        const user = await renderList(withExtras());

        await user.click(
            (await screen.findAllByRole('button', { name: /edit/i }))[0],
        );
        expect(
            screen.getByRole('button', { name: /Remove All Laptop/i }),
        ).toBeInTheDocument();

        await user.click(screen.getAllByRole('button', { name: /close/i })[0]);
        await user.click(
            screen.getByRole('button', {
                name: /Add New Product|Add Product/i,
            }),
        );

        expect(
            screen.queryByRole('button', { name: /Remove All Laptop/i }),
        ).not.toBeInTheDocument();
    });

    /* And in the table, where a column of bare leaves said very little. */
    it('names the shelf by its ancestry in the list', async () => {
        await renderList(
            listing({
                category: {
                    id: 411,
                    name: 'Gaming Laptop',
                    parent: { id: 395, name: 'Laptop' },
                },
            }),
        );

        expect(await screen.findByText('Laptop ›')).toBeInTheDocument();
    });

    it('says so plainly when a product is on no shelf', async () => {
        await renderList(listing({ category: null, category_id: null }));

        expect(await screen.findByText('Unfiled')).toBeInTheDocument();
    });

    /* The primary field, for the same reason as the chips beside it. */
    it('names the primary shelf by its ancestry too', async () => {
        const user = await renderList(
            listing({
                category_id: 411,
                category: {
                    id: 411,
                    name: 'Gaming Laptop',
                    parent: { id: 395, name: 'Laptop' },
                },
                categories: [],
            }),
        );
        await user.click(
            (await screen.findAllByRole('button', { name: /edit/i }))[0],
        );

        // Asked of the field, not the page: the table row names the category
        // as well, so "Gaming Laptop" matches twice.
        const field = document.querySelector('.category-picker-chosen');

        expect(field).not.toBeNull();
        expect(field.textContent).toMatch(/Laptop ›\s*Gaming Laptop/);
    });

    // ── the dialogs that interrupt the modal ─────────────────────────

    /**
     * A dialog that interrupts the product modal has to paint over it.
     *
     * Every backdrop in the shop is z-index 9999, so with equal stacking the
     * later element in the DOM wins. These were declared above the modal and
     * drawn underneath it: pressing Cancel opened the question and hid it
     * behind the form, so the modal looked like it had ignored the click.
     *
     * jsdom has neither painting nor stacking — `findByText` found the dialog
     * whichever way round it was, which is why the tests stayed green while
     * the screen did not work. Document order is the part that can be checked
     * here, and it is the part that decides.
     */
    const backdrops = () =>
        Array.from(document.querySelectorAll('.modal-backdrop-overlay'));

    it('draws the discard question over the form, not under it', async () => {
        const user = await openCreate();

        fireEvent.change(screen.getByLabelText(/Product Title/i), {
            target: { value: 'Half-typed' },
        });

        await user.click(screen.getAllByRole('button', { name: /close/i })[0]);

        expect(
            await screen.findByText(/Discard this product\?/i),
        ).toBeInTheDocument();

        const open = backdrops();
        expect(open.length).toBe(2);

        // The question is the last one, so it is the one on top.
        expect(open[1].textContent).toMatch(/Discard this product\?/i);
    });

    it('draws the publish question over the form too', async () => {
        const user = await openCreate();
        await fillMinimum(user);
        await publish(user);

        expect(
            await screen.findByText(/Publish it like this\?/i),
        ).toBeInTheDocument();

        const open = backdrops();
        expect(open.length).toBe(2);
        expect(open[1].textContent).toMatch(/Publish it like this\?/i);
    });

    // ── copying one ──────────────────────────────────────────────────

    /*
     * Entering a product is six panels of work, and most of a range differs
     * from something already listed by two or three lines. The copy is a
     * draft, so nothing reaches shoppers half-finished.
     */
    it('copies a product from the list', async () => {
        const user = await renderList(listing());

        await user.click(
            await screen.findByRole('button', { name: /copy existing/i }),
        );

        await waitFor(() => expect(duplicateProduct).toHaveBeenCalledWith(9));
    });

    /* The server names the copy; the toast should say what it said. */
    it('passes on what the server called it', async () => {
        const user = await renderList(listing());

        await user.click(
            await screen.findByRole('button', { name: /copy existing/i }),
        );

        await waitFor(() =>
            expect(toastSuccess).toHaveBeenCalledWith(
                "Copied to 'Existing (Copy)', saved as a draft.",
            ),
        );
    });

    /*
     * Straight into the copy: the edit that makes it a different product is
     * the point, and a copy nobody finishes is another thin product in the
     * list — which is the thing the draft default exists to prevent.
     */
    it('opens the copy so it can be finished', async () => {
        const user = await renderList(listing());

        await user.click(
            await screen.findByRole('button', { name: /copy existing/i }),
        );

        expect(
            await screen.findByDisplayValue('Existing (Copy)'),
        ).toBeInTheDocument();
    });

    it('says so when the copy is refused', async () => {
        duplicateProduct.mockRejectedValue({ message: 'Nope' });
        const user = await renderList(listing());

        await user.click(
            await screen.findByRole('button', { name: /copy existing/i }),
        );

        await waitFor(() => expect(toastError).toHaveBeenCalledWith('Nope'));
    });

    // ── the walk through the six panels ──────────────────────────────

    it('opens on the first step and offers no way back from it', async () => {
        await openCreate();

        expect(screen.getByText(/Step 1 of 6/i)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /previous/i }),
        ).toBeDisabled();
    });

    it('walks forward and back without saving anything', async () => {
        const user = await openCreate();

        await user.click(screen.getByRole('button', { name: /^next$/i }));
        expect(screen.getByText(/Step 2 of 6/i)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /previous/i }));
        expect(screen.getByText(/Step 1 of 6/i)).toBeInTheDocument();

        expect(createProduct).not.toHaveBeenCalled();
    });

    /* Clicking a tab header and pressing Next read the same position. */
    it('keeps the step count in step with the tabs', async () => {
        const user = await openCreate();

        await goTo(user, 'photos');

        expect(screen.getByText(/Step 5 of 6/i)).toBeInTheDocument();
    });

    it('offers Publish Now only on the last step', async () => {
        const user = await openCreate();

        expect(
            screen.queryByRole('button', { name: /publish now/i }),
        ).not.toBeInTheDocument();

        await goTo(user, 'publishing');

        expect(
            screen.getByRole('button', { name: /publish now/i }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^next$/i }),
        ).not.toBeInTheDocument();
    });

    /*
     * The walk is a suggestion about order, not a gate. Somebody who only
     * wants a name and a price should not click through four panels to put it
     * down, so Save as Draft is on every step.
     */
    it('saves a draft from the first step', async () => {
        const user = await openCreate();
        await fillMinimum(user);
        await goTo(user, 'basics');

        await user.click(
            screen.getByRole('button', { name: /save as draft/i }),
        );

        await waitFor(() => expect(createProduct).toHaveBeenCalled());
        expect(createProduct.mock.calls[0][0].is_active).toBe(false);
    });

    /*
     * The button says draft, so it saves a draft — even having ticked the
     * checkbox on the Publishing panel first. Without the explicit set this
     * passes anyway, because draft is the default; it only bites for somebody
     * who ticked Active, thought better of it, and pressed Save as Draft
     * expecting the button to mean what it says.
     */
    it('saves a draft even after Active was ticked', async () => {
        const user = await openCreate();
        await fillMinimum(user);

        await goTo(user, 'publishing');
        await user.click(screen.getByLabelText(/Active in Live Storefront/i));
        expect(
            screen.getByLabelText(/Active in Live Storefront/i),
        ).toBeChecked();

        await user.click(
            screen.getByRole('button', { name: /save as draft/i }),
        );

        await waitFor(() => expect(createProduct).toHaveBeenCalled());
        expect(createProduct.mock.calls[0][0].is_active).toBe(false);
    });

    it('publishes from the last step', async () => {
        const user = await openCreate();
        await fillMinimum(user);
        await goTo(user, 'publishing');

        await user.click(screen.getByRole('button', { name: /publish now/i }));

        // Thin, so it asks first — the same guard as any other publish.
        await user.click(
            await screen.findByRole('button', { name: /publish anyway/i }),
        );

        await waitFor(() => expect(createProduct).toHaveBeenCalled());
        expect(createProduct.mock.calls[0][0].is_active).toBe(true);
    });

    /* Editing is not a walk: correcting a price should not be a six-step job. */
    it('gives an existing product a plain save, not a wizard', async () => {
        const user = userEvent.setup();
        render(
            <Products
                products={{
                    data: [
                        {
                            id: 9,
                            name: 'Existing',
                            price: 100,
                            is_active: true,
                            category_id: 411,
                            has_variants: false,
                            variants: [],
                        },
                    ],
                    meta: {},
                }}
                categories={[]}
                brands={[]}
            />,
        );
        await user.click(
            (await screen.findAllByRole('button', { name: /edit/i }))[0],
        );

        expect(
            screen.getByRole('button', { name: /update product/i }),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Step 1 of 6/i)).not.toBeInTheDocument();
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

    /* Publishing is the last step of the walk, and its one button. */
    const publish = async (user) => {
        await goTo(user, 'publishing');
        await user.click(screen.getByRole('button', { name: /publish now/i }));
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

        await user.click(
            screen.getByRole('button', { name: /save as draft/i }),
        );

        expect(
            screen.queryByText(/Publish it like this\?/i),
        ).not.toBeInTheDocument();
    });
});
