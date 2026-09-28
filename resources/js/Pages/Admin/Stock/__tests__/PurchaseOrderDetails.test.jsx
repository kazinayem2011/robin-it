import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const adminService = vi.hoisted(() => ({ getPurchaseOrder: vi.fn() }));
vi.mock('@/services', () => ({ adminService }));

import PurchaseOrderDetailsModal from '../../Components/PurchaseOrderDetailsModal';

const details = (status = 'partial') => ({
    order: {
        id: 4,
        reference: 'PO-20261002-001',
        status,
        status_label: status === 'partial' ? 'Part delivered' : 'Delivered',
        supplier_name: 'Star Tech Limited',
        ordered_by_name: 'Nayem',
        created_at: '2026-10-02T09:00:00Z',
        expected_on: '2026-10-09',
        note: 'Urgent',
        total_quantity: 10,
        total_cost: 600000,
        outstanding: status === 'partial' ? 4 : 0,
        items: [
            {
                id: 1,
                display_name: 'ASUS Vivobook',
                quantity: 10,
                quantity_received: 6,
                outstanding: 4,
                unit_cost: 60000,
            },
        ],
    },
    deliveries: [
        {
            id: 9,
            reference: 'GRN-AAAA',
            received_on: '2026-10-03',
            invoice_number: 'INV-77',
            note: 'Two boxes dented',
            received_by: 'Rahim',
            total_quantity: 6,
            lines: [
                {
                    name: 'ASUS Vivobook',
                    quantity: 6,
                    branches: [
                        { name: 'Multiplan', quantity: 4 },
                        { name: 'Uttara', quantity: 2 },
                    ],
                    serials: ['SN1', 'SN2'],
                },
            ],
        },
    ],
});

// A block, not an arrow that returns the mock: a function returned from
// beforeEach is run as cleanup, and would ask for the order after each test.
beforeEach(() => {
    adminService.getPurchaseOrder.mockReset();
});

describe('Purchase order details', () => {
    it('shows each line and every delivery, with where the units went', async () => {
        adminService.getPurchaseOrder.mockResolvedValue(details());
        render(
            <PurchaseOrderDetailsModal
                orderId={4}
                onClose={() => {}}
                onEdit={() => {}}
                onReceive={() => {}}
            />,
        );

        expect(
            await screen.findByText('Purchase order PO-20261002-001'),
        ).toBeInTheDocument();
        expect(screen.getByText('Star Tech Limited')).toBeInTheDocument();
        expect(screen.getByText('Invoice INV-77')).toBeInTheDocument();
        expect(screen.getByText('Received by Rahim')).toBeInTheDocument();
        expect(screen.getByText('Two boxes dented')).toBeInTheDocument();
        expect(
            screen.getByText('Multiplan: 4 · Uttara: 2'),
        ).toBeInTheDocument();
        expect(screen.getByText('Serials: SN1, SN2')).toBeInTheDocument();
        expect(screen.getByText('Deliveries (1)')).toBeInTheDocument();
    });

    it('offers Edit and Receive while the order is open', async () => {
        adminService.getPurchaseOrder.mockResolvedValue(details('partial'));
        const onReceive = vi.fn();
        render(
            <PurchaseOrderDetailsModal
                orderId={4}
                onClose={() => {}}
                onEdit={() => {}}
                onReceive={onReceive}
            />,
        );

        await userEvent.click(
            await screen.findByRole('button', { name: 'Receive delivery' }),
        );
        expect(onReceive).toHaveBeenCalledWith(
            expect.objectContaining({ id: 4 }),
        );
        expect(
            screen.getByRole('button', { name: 'Edit order' }),
        ).toBeInTheDocument();
    });

    it('offers neither once everything has arrived', async () => {
        adminService.getPurchaseOrder.mockResolvedValue(details('received'));
        render(
            <PurchaseOrderDetailsModal
                orderId={4}
                onClose={() => {}}
                onEdit={() => {}}
                onReceive={() => {}}
            />,
        );

        await screen.findByText('Purchase order PO-20261002-001');
        expect(
            screen.queryByRole('button', { name: 'Receive delivery' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit order' }),
        ).not.toBeInTheDocument();
    });
});

describe('Purchase order details while loading', () => {
    it('shows a skeleton of the order, not a sentence', () => {
        adminService.getPurchaseOrder.mockReturnValue(new Promise(() => {}));
        const { container } = render(
            <PurchaseOrderDetailsModal
                orderId={4}
                onClose={() => {}}
                onEdit={() => {}}
                onReceive={() => {}}
            />,
        );

        expect(screen.getByLabelText('Loading the order')).toHaveAttribute(
            'aria-busy',
            'true',
        );
        expect(
            container.ownerDocument.querySelectorAll('.skeleton-shimmer')
                .length,
        ).toBeGreaterThan(8);
        expect(screen.queryByText('Loading…')).not.toBeInTheDocument();
    });
});
