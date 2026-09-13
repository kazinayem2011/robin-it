import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const post = vi.fn();
const patch = vi.fn();
const uploadImage = vi.fn();
const deleteImage = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn(), get: vi.fn() },
    usePage: () => ({ props: {}, url: '/admin/brands' }),
}));
vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('@/Components/Toast', () => ({
    toast: { error: toastError, success: toastSuccess },
}));
vi.mock('../../../services/axiosInstance', () => ({
    default: { post, patch, delete: vi.fn(), get: vi.fn() },
}));
vi.mock('@/services', () => ({
    uploadService: { uploadImage, deleteImage },
}));

const { default: Brands } = await import('../Brands');

/**
 * When a chosen logo actually goes up.
 *
 * Picking one used to upload it there and then. A logo chosen and then thought
 * better of — the modal closed, the name left blank, the save refused — left a
 * file on the disk that nothing ever pointed at, and no screen lists those. It
 * waits for the brand to be saved now, and if the save is refused it is taken
 * back down again.
 */
describe('the brand logo', () => {
    const file = () => new File(['x'], 'asus.png', { type: 'image/png' });

    beforeEach(() => {
        vi.clearAllMocks();
        uploadImage.mockResolvedValue({
            path: '/storage/uploads/brands/a.png',
        });
        deleteImage.mockResolvedValue({});
        post.mockResolvedValue({});
        patch.mockResolvedValue({});

        // jsdom has no blob URLs of its own.
        global.URL.createObjectURL = vi.fn(() => 'blob:preview');
        global.URL.revokeObjectURL = vi.fn();
    });

    const openForm = async () => {
        const user = userEvent.setup();
        render(<Brands brands={{ data: [] }} filters={{}} counts={{}} />);
        await user.click(screen.getByRole('button', { name: /add brand/i }));
        return user;
    };

    /*
     * The input is hidden behind an Upload button, so userEvent refuses to
     * touch it and a plain fireEvent hands over a `files` that the change
     * handler reads as empty — which is how the first draft of these tests
     * asserted "nothing was uploaded" against a pick that never happened.
     * Defining the property is what actually puts a file on the element.
     */
    const pick = () => {
        const input = document.querySelector('input[type="file"]');
        const chosen = file();

        Object.defineProperty(input, 'files', {
            value: [chosen],
            configurable: true,
        });

        fireEvent.change(input);

        return chosen;
    };

    it('does not upload the moment a file is chosen', async () => {
        await openForm();

        pick();

        expect(uploadImage).not.toHaveBeenCalled();
    });

    /* It still has to be visible, or nobody knows what they picked. */
    it('shows the chosen file without sending it anywhere', async () => {
        await openForm();

        pick();

        expect(global.URL.createObjectURL).toHaveBeenCalled();
        expect(uploadImage).not.toHaveBeenCalled();
    });

    it('uploads it when the brand is saved', async () => {
        const user = await openForm();

        await user.type(screen.getByLabelText(/brand name/i), 'Logitech');
        pick();
        await user.click(
            screen.getByRole('button', { name: /^create brand$/i }),
        );

        await waitFor(() => expect(uploadImage).toHaveBeenCalled());
        expect(post).toHaveBeenCalledWith(
            expect.anything(),
            expect.objectContaining({
                name: 'Logitech',
                logo_path: '/storage/uploads/brands/a.png',
            }),
        );
    });

    /*
     * The case this was reported for. A refused save used to leave the picture
     * on the disk with nothing pointing at it.
     */
    it('sends nothing when the form is refused before saving', async () => {
        const user = await openForm();

        pick();
        // No name, so the form refuses without ever reaching the server.
        await user.click(
            screen.getByRole('button', { name: /^create brand$/i }),
        );

        expect(uploadImage).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
        expect(toastError).toHaveBeenCalled();
    });

    /*
     * And if the brand itself is refused after the picture went up, it comes
     * back down: nothing references it, and the media endpoint only refuses to
     * delete what something still uses.
     */
    it('takes the picture back down when the brand is refused', async () => {
        post.mockRejectedValue({ message: 'That name is taken.' });
        const user = await openForm();

        await user.type(screen.getByLabelText(/brand name/i), 'ASUS');
        pick();
        await user.click(
            screen.getByRole('button', { name: /^create brand$/i }),
        );

        await waitFor(() =>
            expect(deleteImage).toHaveBeenCalledWith(
                '/storage/uploads/brands/a.png',
            ),
        );
        expect(toastError).toHaveBeenCalledWith('That name is taken.');
    });

    /* A pick belongs to the brand it was made for. */
    it('drops the pick when the form is closed', async () => {
        const user = await openForm();

        pick();
        await user.click(screen.getAllByRole('button', { name: /close/i })[0]);
        await user.click(screen.getByRole('button', { name: /add brand/i }));

        await user.type(screen.getByLabelText(/brand name/i), 'Logitech');
        await user.click(
            screen.getByRole('button', { name: /^create brand$/i }),
        );

        await waitFor(() => expect(post).toHaveBeenCalled());
        expect(uploadImage).not.toHaveBeenCalled();
    });
});
