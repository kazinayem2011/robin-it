import React from 'react';
import { readFileSync } from 'node:fs';
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
}));
vi.mock('@/services', () => ({ adminService }));

import ReceiveStockModal from '../ReceiveStockModal';

const stores = [
    { id: 1, name: 'Khulna Branch', fulfils_online: true },
    { id: 2, name: 'Uttara Showroom', fulfils_online: false },
];

const suppliers = [{ id: 5, name: 'AJAZZ Distribution', kind: 'trade' }];

const units = [
    {
        id: 898,
        name: 'Sample AJAZZ',
        has_variants: false,
        stock_quantity: 0,
        category: { id: 3, name: 'AJAZZ Mice' },
    },
];

beforeEach(() => {
    adminService.getStockUnits.mockReset().mockResolvedValue({ data: units });
    adminService.receiveStock
        .mockReset()
        .mockResolvedValue({ reference: 'GRN-1', total_quantity: 10 });
});

describe('Receive stock', () => {
    /*
     * Stock used to land in whichever branch the server fell back to — one the
     * person receiving never chose and the form never named.
     */
    it('names the branch, starting with the one that ships online orders', async () => {
        render(
            <ReceiveStockModal
                isOpen
                suppliers={suppliers}
                stores={stores}
                onClose={vi.fn()}
                onSaved={vi.fn()}
            />,
        );

        // The shop's own dropdown: a combobox showing what is chosen.
        const branch = await screen.findByRole('combobox', {
            name: /Into branch/,
        });
        expect(branch).toHaveTextContent('Khulna Branch — ships online orders');
    });

    it('sends the branch with the delivery', async () => {
        render(
            <ReceiveStockModal
                isOpen
                suppliers={suppliers}
                stores={stores}
                onClose={vi.fn()}
                onSaved={vi.fn()}
            />,
        );

        await userEvent.click(
            await screen.findByRole('combobox', { name: /Into branch/ }),
        );
        await userEvent.click(await screen.findByText('Uttara Showroom'));
        await userEvent.click(await screen.findByText('Choose a product…'));
        await userEvent.click(await screen.findByText(/Sample AJAZZ/));
        // Quantity first, then the cost beside it: neither input carries an
        // id the label points at, so they are found by what they are.
        const numbers = screen.getAllByRole('spinbutton');
        await userEvent.type(numbers[0], '10');
        await userEvent.type(screen.getByPlaceholderText('Optional'), '900');
        await userEvent.click(
            screen.getByRole('button', { name: /Receive into stock/ }),
        );

        await waitFor(() =>
            expect(adminService.receiveStock).toHaveBeenCalledTimes(1),
        );
        expect(adminService.receiveStock.mock.calls[0][0]).toEqual(
            expect.objectContaining({ store_id: '2' }),
        );
    });

    /** Four products share the name "Sample AJAZZ"; the shelf tells them apart. */
    it('names the shelf beside each product', async () => {
        render(
            <ReceiveStockModal
                isOpen
                suppliers={suppliers}
                stores={stores}
                onClose={vi.fn()}
                onSaved={vi.fn()}
            />,
        );

        await userEvent.click(await screen.findByText('Choose a product…'));

        expect(
            await screen.findByText('Sample AJAZZ — AJAZZ Mice'),
        ).toBeInTheDocument();
    });
});

/**
 * The bin belongs in the row it deletes.
 *
 * The line is a grid of five cells — product, quantity, cost, serials, bin —
 * and the rule declared four columns, so the bin wrapped onto a row of its own
 * under every line. Read from the stylesheet, because jsdom lays nothing out.
 */
describe('the receive line', () => {
    it('has a column for every cell in it', () => {
        const css = readFileSync(
            'resources/js/Layouts/AdminLayout.css',
            'utf8',
        );
        const at = css.indexOf('.admin-receive-line {');
        const rule = css.slice(at, css.indexOf('}', at));
        const columns = /grid-template-columns:\s*([^;]+)/.exec(rule)?.[1];

        expect(columns).toBeTruthy();
        // minmax(...) counts as one column, whatever commas it holds inside.
        const cells = columns
            .replace(/minmax\([^)]*\)/g, 'x')
            .trim()
            .split(/\s+/);
        expect(cells).toHaveLength(5);
    });
});
