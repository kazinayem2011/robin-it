import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));
const toast = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
}));
vi.mock('@/Components/Toast', () => ({ toast }));

const adminService = vi.hoisted(() => ({
    getStockUnits: vi.fn(),
    receiveStock: vi.fn(),
    receivePurchaseOrder: vi.fn(),
}));
vi.mock('@/services', () => ({ adminService }));

import ReceiveDeliveryModal from '../../Components/ReceiveDeliveryModal';

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
            quantity: 3,
            quantity_received: 0,
            unit_cost: 60000,
            needs_serials: true,
        },
    ],
};

beforeEach(() => {
    vi.clearAllMocks();
    adminService.getStockUnits.mockResolvedValue({ data: [] });
    adminService.receivePurchaseOrder.mockResolvedValue({ message: 'ok' });
});

/*
 * Serials are optional at the door, at the owner's asking. A warranty
 * product without them still saves, and the window says how many are still
 * to add and where.
 */
describe('Receive delivery, serials optional', () => {
    it('saves a warranty product without serials, saying how many are still to add', async () => {
        const person = userEvent.setup();
        render(
            <ReceiveDeliveryModal
                isOpen
                order={order}
                stores={[
                    { id: 1, name: 'Khulna Branch', fulfils_online: true },
                ]}
                onClose={() => {}}
                onSaved={() => {}}
            />,
        );

        expect(
            screen.getByText(
                /ASUS Vivobook: 3 of 3 without a serial\. You can add them later under Stock → Serial numbers → Add serials\./,
            ),
        ).toBeInTheDocument();

        await person.click(
            screen.getByRole('button', { name: 'Save delivery' }),
        );

        await waitFor(() =>
            expect(adminService.receivePurchaseOrder).toHaveBeenCalled(),
        );
        expect(toast.error).not.toHaveBeenCalled();
        expect(toast.info).toHaveBeenCalledWith(
            expect.stringContaining('3 of 3 without a serial'),
            'Serials still to add',
        );
    });
});
