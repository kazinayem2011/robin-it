import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const router = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router }));
vi.mock('@/Layouts/MainLayout', () => ({ mainLayout: (page) => page }));
vi.mock('@/Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('../AccountLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));
vi.mock('../ContactVerification', () => ({ default: () => null }));

import Profile from '../Profile';

const user = { name: 'Karim', email: '', phone: '01712345678' };

beforeEach(() => router.put.mockReset());

const fillNewPassword = async () => {
    await userEvent.type(
        screen.getByPlaceholderText('At least 8 characters'),
        'new-secret-1',
    );
    await userEvent.type(
        screen.getByPlaceholderText('Repeat the new password'),
        'new-secret-1',
    );
};

describe('Profile password form', () => {
    /*
     * An account made at checkout has no password, so there is no current one
     * to type. Asking for it left the owner unable to set one here at all.
     */
    it('sets a first password without asking for a current one', async () => {
        render(<Profile user={user} hasPassword={false} />);

        expect(screen.getByText('Set a Password')).toBeInTheDocument();
        expect(
            screen.queryByPlaceholderText('Your current password'),
        ).toBeNull();

        await fillNewPassword();
        await userEvent.click(
            screen.getByRole('button', { name: 'Set Password' }),
        );

        await waitFor(() => expect(router.put).toHaveBeenCalledTimes(1));
    });

    it('still asks for the current password to change one', async () => {
        render(<Profile user={user} hasPassword />);

        expect(
            screen.getByPlaceholderText('Your current password'),
        ).toBeInTheDocument();

        await fillNewPassword();
        await userEvent.click(
            screen.getByRole('button', { name: 'Update Password' }),
        );

        expect(
            await screen.findByText('Current password is required'),
        ).toBeInTheDocument();
        expect(router.put).not.toHaveBeenCalled();
    });
});
