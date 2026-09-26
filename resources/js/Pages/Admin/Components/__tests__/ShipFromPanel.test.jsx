import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getOrderShipFrom = vi.fn();
const setOrderShipFrom = vi.fn();

vi.mock('@/services', () => ({
    adminService: {
        getOrderShipFrom: (...a) => getOrderShipFrom(...a),
        setOrderShipFrom: (...a) => setOrderShipFrom(...a),
    },
}));
vi.mock('@/Components/Toast', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

import ShipFromPanel from '../ShipFromPanel';

const line = (over = {}) => ({
    order_item_id: 41,
    name: 'ASUS Vivobook',
    units: 3,
    waiting_for_stock: false,
    current: [{ id: 1, units: 3 }],
    branches: [
        { id: 1, name: 'Khulna', available: 3 },
        { id: 2, name: 'Dhaka', available: 2 },
        { id: 3, name: 'Jessore', available: 5 },
    ],
    ...over,
});

const order = (over = {}) => ({
    id: 9,
    ship_from: [{ id: 1, name: 'Khulna', units: 3 }],
    can_change_ship_from: true,
    ...over,
});

const pick = async (person, name) => {
    await waitFor(() => expect(screen.getByRole('combobox')).toBeEnabled());
    await person.click(screen.getByRole('combobox'));
    await person.click(screen.getByRole('option', { name }));
};

/*
 * Branch by item: each item says where it comes from, each branch what it can
 * give, and a line can be split so two branches cover what neither can alone.
 */
describe('ShipFromPanel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getOrderShipFrom.mockResolvedValue({ lines: [line()] });
        setOrderShipFrom.mockResolvedValue({
            message: 'Updated.',
            data: { ship_from: [] },
        });
    });

    it('lists each item with its own choice of branch', async () => {
        const person = userEvent.setup();
        render(<ShipFromPanel order={order()} />);

        expect(await screen.findByText('ASUS Vivobook')).toBeInTheDocument();
        await person.click(screen.getByRole('combobox'));

        expect(
            screen.getByRole('option', {
                name: 'Khulna — ships from here now',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Jessore — 5 available' }),
        ).not.toBeDisabled();
        // Dhaka cannot take all three on its own.
        expect(
            screen.getByRole('option', { name: 'Dhaka — 2 available' }),
        ).toBeDisabled();
    });

    it('moves an item to the branch chosen for it', async () => {
        const person = userEvent.setup();
        const onChanged = vi.fn();
        render(<ShipFromPanel order={order()} onChanged={onChanged} />);

        await pick(person, 'Jessore — 5 available');

        await waitFor(() =>
            expect(setOrderShipFrom).toHaveBeenCalledWith(9, {
                lines: [{ order_item_id: 41, stores: { 3: 3 } }],
            }),
        );
        expect(onChanged).toHaveBeenCalled();
    });

    it('splits an item between branches, only once it adds up', async () => {
        const person = userEvent.setup();
        render(<ShipFromPanel order={order()} />);

        await pick(person, 'Split between branches…');

        const khulna = screen.getByLabelText('ASUS Vivobook from Khulna');
        const dhaka = screen.getByLabelText('ASUS Vivobook from Dhaka');
        const save = screen.getByRole('button', { name: /save split/i });

        await person.clear(khulna);
        await person.type(khulna, '1');
        expect(save).toBeDisabled();
        expect(screen.getByText('1 of 3 placed')).toBeInTheDocument();

        await person.type(dhaka, '2');
        expect(save).toBeEnabled();
        await person.click(save);

        await waitFor(() =>
            expect(setOrderShipFrom).toHaveBeenCalledWith(9, {
                lines: [{ order_item_id: 41, stores: { 1: 1, 2: 2 } }],
            }),
        );
    });

    it('will not split more onto a branch than it has', async () => {
        const person = userEvent.setup();
        render(<ShipFromPanel order={order()} />);

        await pick(person, 'Split between branches…');
        const khulna = screen.getByLabelText('ASUS Vivobook from Khulna');
        await person.clear(khulna);
        await person.type(
            screen.getByLabelText('ASUS Vivobook from Dhaka'),
            '3',
        );

        expect(screen.getByText('Dhaka has only 2.')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /save split/i }),
        ).toBeDisabled();
    });

    it('marks an item waiting for stock', async () => {
        getOrderShipFrom.mockResolvedValue({
            lines: [line({ owed: true, waiting_for_stock: true })],
        });
        render(<ShipFromPanel order={order()} />);

        expect(
            await screen.findByText('Waiting for stock'),
        ).toBeInTheDocument();
    });

    it('offers no change once the order is dispatched', () => {
        render(
            <ShipFromPanel order={order({ can_change_ship_from: false })} />,
        );

        expect(screen.getByText('Khulna')).toBeInTheDocument();
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
        expect(getOrderShipFrom).not.toHaveBeenCalled();
    });
});
