import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const router = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }) => children,
    router,
}));
const otpService = vi.hoisted(() => ({ forRegistration: vi.fn() }));
vi.mock('../../../services', () => ({ otpService }));

import Register from '../Register';

/*
 * With texts switched on, an email-only sign-up was shown "Send me a code",
 * which asked the server to text nobody and stopped at an error on the empty
 * phone field — and pressing past it demanded a code that was never sent. The
 * account could not be made. A code belongs to a number, so without one the
 * form creates the account straight away.
 */
describe('Sign-up with texts switched on', () => {
    beforeEach(() => {
        router.post.mockReset();
        otpService.forRegistration.mockReset().mockResolvedValue({});
    });

    const fill = async (user, { phone = '', email = '' }) => {
        await user.type(screen.getByLabelText(/Full Name/i), 'Rahim Uddin');
        if (email) await user.type(screen.getByLabelText(/Email/i), email);
        if (phone) await user.type(screen.getByLabelText(/Mobile/i), phone);
        await user.type(document.querySelector('#password'), 'Secret-2026');
        await user.type(
            document.querySelector('#password_confirmation'),
            'Secret-2026',
        );
    };

    it('creates an email-only account without asking for a code', async () => {
        const user = userEvent.setup();
        render(<Register verifyPhone />);
        await fill(user, { email: 'rahim@example.com' });

        const button = screen.getByRole('button', {
            name: /CREATE MY ACCOUNT/i,
        });
        await user.click(button);

        await waitFor(() => expect(router.post).toHaveBeenCalled());
        expect(otpService.forRegistration).not.toHaveBeenCalled();
    });

    it('still sends a code first when a number is given', async () => {
        const user = userEvent.setup();
        render(<Register verifyPhone />);
        await fill(user, { phone: '01712345678' });

        await user.click(
            screen.getByRole('button', { name: /SEND ME A CODE/i }),
        );

        await waitFor(() =>
            expect(otpService.forRegistration).toHaveBeenCalledWith(
                '01712345678',
            ),
        );
        expect(router.post).not.toHaveBeenCalled();
    });
});
