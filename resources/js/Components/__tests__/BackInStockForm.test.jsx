import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import BackInStockForm from '../BackInStockForm';

vi.mock('../../services/stockNotificationService', () => ({
    default: {
        subscribe: vi.fn(() => Promise.resolve({ waiting: 1 })),
        count: vi.fn(() => Promise.resolve({ waiting: 0 })),
    },
}));

/**
 * Asking a signed-in shopper for an address the shop already has.
 *
 * The form took a prefill and nothing passed one, so somebody signed in was
 * handed an empty box. Filling it in is only half of it: when the address
 * comes off the account there is nothing to ask and nothing to get wrong, so
 * the field is locked. An account without one — which registration does not
 * currently allow, but the form must not assume — still gets a box to type in.
 */
describe('BackInStockForm', () => {
    beforeEach(() => vi.clearAllMocks());

    const field = () => screen.getByRole('textbox', { name: /email/i });

    it('locks the field to the address on the account', async () => {
        render(
            <BackInStockForm productId={1} accountEmail="robin@example.com" />,
        );

        await waitFor(() => expect(field()).toBeDisabled());
        expect(field()).toHaveValue('robin@example.com');
    });

    it('says it will write to them rather than asking', async () => {
        render(
            <BackInStockForm productId={1} accountEmail="robin@example.com" />,
        );

        await waitFor(() =>
            expect(screen.getByText(/we’ll email you/i)).toBeInTheDocument(),
        );
    });

    it('leaves the field open when the account carries no address', async () => {
        render(<BackInStockForm productId={1} accountEmail="" />);

        await waitFor(() => expect(field()).toBeEnabled());
        expect(field()).toHaveValue('');
        expect(screen.getByText(/leave your email/i)).toBeInTheDocument();
    });

    it('leaves the field open for a guest', async () => {
        render(<BackInStockForm productId={1} />);

        await waitFor(() => expect(field()).toBeEnabled());
    });
});
