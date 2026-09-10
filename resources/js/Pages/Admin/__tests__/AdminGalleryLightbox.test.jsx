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
vi.mock('../../../services/axiosInstance', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: [] }),
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
vi.mock('@/Components/RichTextEditor', () => ({ default: () => null }));
vi.mock('@/Components/ImageGalleryEditor', () => ({ default: () => null }));
vi.mock('../Components/ProductDetailsModal', () => ({ default: () => null }));

const { default: Products } = await import('../Products');

/**
 * The admin's way into a product's photographs.
 *
 * The list draws a 40px thumbnail per row, and that was the whole of what
 * somebody checking the catalogue could see of a product's photography without
 * opening the edit form. The storefront gained a viewer; this is the same one,
 * from the same place.
 */
describe('the admin product list', () => {
    const PRODUCTS = [
        {
            id: 1,
            name: 'A Laptop',
            price: 1000,
            stock_quantity: 4,
            is_active: true,
            images: [{ image_path: '/one.jpg' }, { image_path: '/two.jpg' }],
            category: { name: 'Laptop' },
        },
    ];

    beforeEach(() => vi.clearAllMocks());

    const open = async () => {
        const user = userEvent.setup();
        render(
            <Products
                products={{ data: PRODUCTS, meta: {} }}
                categories={[]}
                brands={[]}
            />,
        );

        return user;
    };

    it('offers each row’s thumbnail as a way in', async () => {
        await open();

        expect(
            await screen.findByRole('button', {
                name: /View photos of A Laptop/i,
            }),
        ).toBeTruthy();
    });

    it('opens the viewer on that product’s photos', async () => {
        const user = await open();

        await user.click(
            await screen.findByRole('button', {
                name: /View photos of A Laptop/i,
            }),
        );

        expect(
            await screen.findByRole('dialog', { name: /photos/i }),
        ).toBeTruthy();
        expect(screen.getByText('1 of 2')).toBeTruthy();
    });

    it('moves between them and closes again', async () => {
        const user = await open();

        await user.click(
            await screen.findByRole('button', {
                name: /View photos of A Laptop/i,
            }),
        );
        await screen.findByRole('dialog', { name: /photos/i });

        await user.click(screen.getByLabelText('Next photo'));
        expect(screen.getByText('2 of 2')).toBeTruthy();

        await user.click(screen.getByLabelText('Close photos'));
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: /photos/i }),
            ).toBeNull(),
        );
    });

    /* Opening a second product starts at its first photo, not the last index. */
    it('starts at the first photo each time', async () => {
        const user = await open();
        const opener = await screen.findByRole('button', {
            name: /View photos of A Laptop/i,
        });

        await user.click(opener);
        await user.click(screen.getByLabelText('Next photo'));
        expect(screen.getByText('2 of 2')).toBeTruthy();

        await user.click(screen.getByLabelText('Close photos'));
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: /photos/i }),
            ).toBeNull(),
        );

        await user.click(opener);
        expect(screen.getByText('1 of 2')).toBeTruthy();
    });
}, 20000);
