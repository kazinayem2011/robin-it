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

const stores = [
    { id: 1, name: 'Khulna Branch', fulfils_online: true },
    { id: 2, name: 'Uttara Showroom', fulfils_online: false },
];

const order = {
    id: 12,
    reference: 'PO-0012',
    supplier_name: 'Star Supplier',
    store_id: 1,
    items: [
        {
            id: 41,
            product_id: 7,
            display_name: 'ASUS Vivobook',
            quantity: 10,
            quantity_received: 0,
            unit_cost: 60000,
        },
    ],
};

beforeEach(() => {
    adminService.getStockUnits.mockReset().mockResolvedValue({ data: [] });
    adminService.receiveStock.mockReset().mockResolvedValue({ message: 'ok' });
    adminService.receivePurchaseOrder
        .mockReset()
        .mockResolvedValue({ message: 'ok' });
});

const open = (props = {}) =>
    render(
        <ReceiveDeliveryModal
            isOpen
            order={order}
            stores={stores}
            onClose={() => {}}
            onSaved={() => {}}
            {...props}
        />,
    );

/*
 * One screen for receiving, from a purchase order or on its own. Everything
 * goes to one branch unless a product is split, and the split must add up.
 */
describe('Receive delivery', () => {
    it('starts with what is still to come, all going to the primary branch', async () => {
        open();

        expect(screen.getByText('ASUS Vivobook')).toBeInTheDocument();
        expect(screen.getByText('Ordered 10')).toBeInTheDocument();
        expect(screen.getByDisplayValue('10')).toBeInTheDocument();
        expect(
            screen.getByText('All 10 go to Khulna Branch'),
        ).toBeInTheDocument();
    });

    it('saves a normal delivery in one press', async () => {
        const person = userEvent.setup();
        open();

        await person.click(
            screen.getByRole('button', { name: /save delivery/i }),
        );

        await waitFor(() =>
            expect(adminService.receivePurchaseOrder).toHaveBeenCalledWith(12, {
                store_id: 1,
                invoice_number: null,
                // Today, unless it is changed.
                received_on: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
                note: null,
                lines: [
                    {
                        purchase_order_item_id: 41,
                        quantity: 10,
                        unit_cost: 60000,
                        branches: null,
                        serials: null,
                    },
                ],
            }),
        );
    });

    /* Entered late, with a note: both are kept. */
    it('sends the day it came in and a note', async () => {
        const person = userEvent.setup();
        open();

        const date = screen.getByLabelText(/received on/i);
        await person.clear(date);
        await person.type(date, '2026-09-20');
        await person.type(
            screen.getByLabelText(/note \(optional\)/i),
            'One box dented',
        );
        await person.click(
            screen.getByRole('button', { name: /save delivery/i }),
        );

        await waitFor(() =>
            expect(
                adminService.receivePurchaseOrder.mock.calls[0][1],
            ).toMatchObject({
                received_on: '2026-09-20',
                note: 'One box dented',
            }),
        );
    });

    it('splits a product between branches once it adds up', async () => {
        const person = userEvent.setup();
        open();

        await person.click(screen.getByRole('button', { name: /split/i }));
        const khulna = screen.getByLabelText('ASUS Vivobook to Khulna Branch');
        const uttara = screen.getByLabelText(
            'ASUS Vivobook to Uttara Showroom',
        );

        // Starts with all 10 in the branch chosen above.
        expect(khulna).toHaveValue(10);
        expect(screen.getByText('✓ All 10 placed')).toBeInTheDocument();

        await person.clear(khulna);
        await person.type(khulna, '6');
        expect(screen.getByText('6 of 10 placed')).toBeInTheDocument();

        await person.type(uttara, '4');
        expect(screen.getByText('✓ All 10 placed')).toBeInTheDocument();

        await person.click(
            screen.getByRole('button', { name: /save delivery/i }),
        );

        await waitFor(() =>
            expect(
                adminService.receivePurchaseOrder.mock.calls[0][1].lines[0]
                    .branches,
            ).toEqual({ 1: 6, 2: 4 }),
        );
    });

    it('will not save a split that does not add up, and says why', async () => {
        const person = userEvent.setup();
        open();

        await person.click(screen.getByRole('button', { name: /split/i }));
        const khulna = screen.getByLabelText('ASUS Vivobook to Khulna Branch');
        await person.clear(khulna);
        await person.type(khulna, '6');
        await person.click(
            screen.getByRole('button', { name: /save delivery/i }),
        );

        expect(
            await screen.findByText(
                'ASUS Vivobook: 10 arrived but 6 placed in branches.',
            ),
        ).toBeInTheDocument();
        expect(adminService.receivePurchaseOrder).not.toHaveBeenCalled();
    });

    it('will not take more than is still to come on the order', async () => {
        const person = userEvent.setup();
        open();

        const arrived = screen.getByDisplayValue('10');
        await person.clear(arrived);
        await person.type(arrived, '12');
        await person.click(
            screen.getByRole('button', { name: /save delivery/i }),
        );

        expect(
            await screen.findByText(
                'ASUS Vivobook: only 10 still to come on this order.',
            ),
        ).toBeInTheDocument();
    });

    it('sends serial numbers typed for a product', async () => {
        const person = userEvent.setup();
        open();

        await person.click(screen.getByRole('button', { name: /serials/i }));
        await person.type(
            screen.getByLabelText(/serial numbers — one per line/i),
            'SN1{enter}SN2',
        );
        await person.click(
            screen.getByRole('button', { name: /save delivery/i }),
        );

        await waitFor(() =>
            expect(
                adminService.receivePurchaseOrder.mock.calls[0][1].lines[0]
                    .serials,
            ).toBe('SN1\nSN2'),
        );
    });

    it('without an order, asks for the supplier and the products', async () => {
        open({ order: null, suppliers: [{ id: 5, name: 'AJAZZ' }] });

        await waitFor(() =>
            expect(adminService.getStockUnits).toHaveBeenCalled(),
        );
        expect(screen.getByText('Supplier')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /add another product/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/receive them from that order/i),
        ).toBeInTheDocument();
    });
});
