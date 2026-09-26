import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import BackInStockForm from '../BackInStockForm';
import stockNotificationService from '../../services/stockNotificationService';

vi.mock('../../services/stockNotificationService', () => ({
    default: {
        subscribe: vi.fn(() => Promise.resolve({ waiting: 1 })),
        count: vi.fn(() => Promise.resolve({ waiting: 0 })),
    },
}));

/**
 * One box for an email address or a mobile number.
 *
 * Signed in, the box is filled from the account and locked: there is nothing
 * to ask and nothing to get wrong. Most accounts here are a mobile number, so
 * that is what fills it when there is no address. A guest types either.
 */
describe('BackInStockForm', () => {
    beforeEach(() => vi.clearAllMocks());

    const field = () =>
        screen.getByRole('textbox', { name: /email or mobile number/i });

    it('locks the field to the address on the account', async () => {
        render(
            <BackInStockForm
                productId={1}
                accountContact="robin@example.com"
            />,
        );

        await waitFor(() => expect(field()).toBeDisabled());
        expect(field()).toHaveValue('robin@example.com');
        expect(screen.getByText(/we’ll email you/i)).toBeInTheDocument();
    });

    it('locks it to the mobile when the account has no address', async () => {
        render(<BackInStockForm productId={1} accountContact="01711223344" />);

        await waitFor(() => expect(field()).toBeDisabled());
        expect(field()).toHaveValue('01711223344');
        expect(screen.getByText(/we’ll text you/i)).toBeInTheDocument();
    });

    it('leaves the field open for a guest, asking for either', async () => {
        render(<BackInStockForm productId={1} />);

        await waitFor(() => expect(field()).toBeEnabled());
        expect(field()).toHaveValue('');
        expect(
            screen.getByText(/leave your email or mobile number/i),
        ).toBeInTheDocument();
    });

    it('sends a mobile number as the contact and says a text will come', async () => {
        render(<BackInStockForm productId={7} />);

        fireEvent.change(field(), { target: { value: '01711 223344' } });
        fireEvent.click(screen.getByRole('button', { name: /notify me/i }));

        await waitFor(() =>
            expect(stockNotificationService.subscribe).toHaveBeenCalledWith({
                product_id: 7,
                product_variant_id: null,
                contact: '01711 223344',
            }),
        );
        expect(await screen.findByText(/a text goes out/i)).toBeInTheDocument();
    });

    it('turns away something that is neither', async () => {
        render(<BackInStockForm productId={7} />);

        fireEvent.change(field(), { target: { value: '12345' } });
        fireEvent.click(screen.getByRole('button', { name: /notify me/i }));

        expect(
            await screen.findByText(/11-digit mobile number/i),
        ).toBeInTheDocument();
        expect(stockNotificationService.subscribe).not.toHaveBeenCalled();
    });
});
