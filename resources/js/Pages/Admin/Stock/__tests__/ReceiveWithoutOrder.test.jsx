import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));
vi.mock('@/Components/Toast', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const adminService = vi.hoisted(() => ({
    getStockUnits: vi.fn(),
    receiveStock: vi.fn(),
    receivePurchaseOrder: vi.fn(),
}));
vi.mock('@/services', () => ({ adminService }));

import ReceiveDeliveryModal from '../../Components/ReceiveDeliveryModal';

const stores = [{ id: 1, name: 'Multiplan', fulfils_online: true }];
const suppliers = [
    { id: 5, name: 'Star Tech', kind: null },
    { id: 9, name: 'Opening balance', kind: 'opening' },
];
const openOrder = {
    id: 44,
    reference: 'PO-20261002-001',
    supplier_name: 'Star Tech',
    store_id: 1,
    items: [
        {
            id: 1,
            product_id: 7,
            product_variant_id: null,
            display_name: 'ASUS Vivobook',
            quantity: 10,
            quantity_received: 6,
            unit_cost: 60000,
        },
    ],
};

beforeEach(() => {
    adminService.getStockUnits.mockReset().mockResolvedValue({
        data: [{ id: 7, name: 'ASUS Vivobook', has_variants: false }],
    });
    adminService.receiveStock.mockReset().mockResolvedValue({ message: 'ok' });
});

const open = () =>
    render(
        <ReceiveDeliveryModal
            isOpen
            order={null}
            stores={stores}
            suppliers={suppliers}
            openOrders={[openOrder]}
            onClose={() => {}}
            onSaved={() => {}}
        />,
    );

const answer = async (user, option) => {
    await user.click(
        screen.getByLabelText('Which order is this delivery for?'),
    );
    await user.click(await screen.findByRole('option', { name: option }));
};

const pickProduct = async (user) => {
    await user.click(screen.getByLabelText('Product'));
    await user.click(
        await screen.findByRole('option', { name: /ASUS Vivobook/ }),
    );
};

describe('Receive delivery — one screen, one question first', () => {
    it('asks which order before anything else', () => {
        open();

        expect(
            screen.getByLabelText('Which order is this delivery for?'),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText('Product')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save delivery' }),
        ).toBeDisabled();
    });

    it('lists open orders with what is still to come, and "No order"', async () => {
        const user = userEvent.setup();
        open();

        await user.click(
            screen.getByLabelText('Which order is this delivery for?'),
        );
        expect(
            await screen.findByRole('option', {
                name: 'PO-20261002-001 · Star Tech · 4 still to come',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'No order — bought directly' }),
        ).toBeInTheDocument();
    });

    it('choosing an order fills in its products', async () => {
        const user = userEvent.setup();
        open();

        await answer(user, 'PO-20261002-001 · Star Tech · 4 still to come');

        expect(
            await screen.findByText('Receive delivery — PO-20261002-001'),
        ).toBeInTheDocument();
        expect(screen.getByText(/ASUS Vivobook/)).toBeInTheDocument();
    });

    it('bought directly: reminds of the open order and switches to it', async () => {
        const user = userEvent.setup();
        open();

        await answer(user, 'No order — bought directly');
        await pickProduct(user);

        expect(
            await screen.findByText(/still has 4 of ASUS Vivobook to come/),
        ).toBeInTheDocument();
        await user.click(
            screen.getByRole('button', {
                name: 'Receive from that order instead',
            }),
        );
        expect(
            await screen.findByText('Receive delivery — PO-20261002-001'),
        ).toBeInTheDocument();
    });

    it('bought directly needs a supplier before it saves', async () => {
        const user = userEvent.setup();
        open();

        await answer(user, 'No order — bought directly');
        await pickProduct(user);
        await user.click(screen.getByRole('button', { name: 'Save delivery' }));

        expect(
            await screen.findByText(
                /Choose the supplier — or "Opening balance"/,
            ),
        ).toBeInTheDocument();
        await waitFor(() =>
            expect(adminService.receiveStock).not.toHaveBeenCalled(),
        );
    });
});
