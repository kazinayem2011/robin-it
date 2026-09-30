import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const refundOrder = vi.fn();

vi.mock('@/Components/Toast', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('@/services', () => ({
    adminService: { refundOrder },
}));

const { default: RefundOrderModal } =
    await import('../Components/RefundOrderModal');

const METHODS = [
    { value: 'cash', label: 'Cash' },
    { value: 'bkash', label: 'bKash' },
];
const REASONS = [
    { value: 'returned', label: 'Goods returned' },
    { value: 'wrong_item', label: 'Wrong item sent' },
];

/** Returned in full and paid in full, with a ৳70 delivery charge. */
const order = {
    id: 9,
    order_number: 'ORD-DELIVERY',
    status: 'returned',
    total: 4070,
    shipping_fee: 70,
    returned_value: 4000,
    refundable_amount: 4070,
    delivery_refunded: false,
    payments: [{ amount: 4070 }],
    refunds: [],
};

/**
 * The delivery charge after a return, the way other shops handle it: kept
 * for a change of mind, given back when the shop was at fault. The form
 * offered the whole ৳4,070 either way, and the order then read "৳70 owed".
 */
describe('Refund — the delivery charge', () => {
    beforeEach(() => refundOrder.mockReset().mockResolvedValue({}));

    const renderIt = () =>
        render(
            <RefundOrderModal
                order={order}
                methods={METHODS}
                reasons={REASONS}
                onClose={() => {}}
                onDone={() => {}}
            />,
        );

    it('offers the goods back and keeps delivery for a change of mind', async () => {
        renderIt();

        await waitFor(() =>
            expect(screen.getByLabelText(/Amount/)).toHaveValue(4000),
        );
        expect(screen.getByLabelText(/delivery charge too/)).not.toBeChecked();
    });

    it('gives delivery back too when the shop sent the wrong item', async () => {
        const user = userEvent.setup();
        renderIt();

        await user.click(screen.getByRole('combobox', { name: /Why/ }));
        await user.click(
            await screen.findByRole('option', { name: /Wrong item sent/ }),
        );

        expect(screen.getByLabelText(/delivery charge too/)).toBeChecked();
        expect(screen.getByLabelText(/Amount/)).toHaveValue(4070);

        await user.click(screen.getByRole('button', { name: /Record refund/ }));

        await waitFor(() => expect(refundOrder).toHaveBeenCalled());
        expect(refundOrder.mock.calls[0][1]).toMatchObject({
            amount: 4070,
            reason: 'wrong_item',
            includes_delivery: true,
        });
    });

    it('does not offer it again once delivery has gone back', () => {
        render(
            <RefundOrderModal
                order={{ ...order, delivery_refunded: true }}
                methods={METHODS}
                reasons={REASONS}
                onClose={() => {}}
                onDone={() => {}}
            />,
        );

        expect(screen.queryByLabelText(/delivery charge too/)).toBeNull();
    });
});
