import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('../../../../Components/Toast', () => ({ toast }));

const adminService = vi.hoisted(() => ({
    getStockBranches: vi.fn(),
    transferStock: vi.fn(),
}));
vi.mock('../../../../services', () => ({ adminService }));

import TransferStockModal from '../TransferStockModal';

const stores = [
    { id: 1, name: 'Uttara' },
    { id: 2, name: 'Khulna' },
];

const target = { product: { id: 7, name: 'ASUS Vivobook' }, variant: null };

beforeEach(() => {
    toast.error.mockReset();
    adminService.transferStock.mockReset().mockResolvedValue({});
    adminService.getStockBranches.mockReset().mockResolvedValue([
        {
            store_id: 1,
            store: 'Uttara',
            quantity: 3,
            serials: [
                { id: 11, serial: 'SN-A' },
                { id: 12, serial: 'SN-B' },
                { id: 13, serial: 'SN-C' },
            ],
        },
        { store_id: 2, store: 'Khulna', quantity: 0, serials: [] },
    ]);
});

const open = () =>
    render(
        <TransferStockModal
            target={target}
            stores={stores}
            onClose={() => {}}
            onSaved={() => {}}
        />,
    );

describe('Transfer — serial numbers go with the boxes', () => {
    it('asks which serials are moving and sends the ticked ones', async () => {
        const user = userEvent.setup();
        open();

        expect(
            await screen.findByText('Which serial numbers are moving?'),
        ).toBeInTheDocument();

        await user.click(screen.getByLabelText('To'));
        await user.click(screen.getByRole('option', { name: 'Khulna' }));
        await user.type(screen.getByLabelText('How many'), '2');
        expect(
            screen.getByText('Tick 2 — 0 ticked so far'),
        ).toBeInTheDocument();

        await user.click(screen.getByLabelText('SN-A'));
        await user.click(screen.getByLabelText('SN-C'));
        expect(screen.getByText('✓ 2 ticked')).toBeInTheDocument();
        // No more than the units moving.
        expect(screen.getByLabelText('SN-B')).toBeDisabled();

        await user.click(screen.getByRole('button', { name: 'Transfer' }));

        await waitFor(() =>
            expect(adminService.transferStock).toHaveBeenCalledWith(
                expect.objectContaining({ quantity: 2, serials: [11, 13] }),
            ),
        );
    });

    it('will not send until the right number are ticked', async () => {
        const user = userEvent.setup();
        open();

        await screen.findByText('Which serial numbers are moving?');
        await user.click(screen.getByLabelText('To'));
        await user.click(screen.getByRole('option', { name: 'Khulna' }));
        await user.type(screen.getByLabelText('How many'), '2');
        await user.click(screen.getByLabelText('SN-A'));
        await user.click(screen.getByRole('button', { name: 'Transfer' }));

        await waitFor(() =>
            expect(toast.error).toHaveBeenCalledWith(
                'Tick the 2 serial numbers of the units you are moving.',
            ),
        );
        expect(adminService.transferStock).not.toHaveBeenCalled();
    });
});
