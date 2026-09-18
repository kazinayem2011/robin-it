import React from 'react';
import { readFileSync } from 'node:fs';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';

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
            screen.getByRole('button', { name: /Book it in/ }),
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
 * A receipt line is one row of controls, and they line up.
 *
 * Three things had it crooked. The grid declared four columns for five cells,
 * so the bin wrapped onto a row of its own beneath the line it deletes. The
 * product cell added a flex gap on top of the label's own bottom margin, so the
 * product box sat 4px below the Qty and Unit cost boxes. And a field carries an
 * 18px bottom margin meant for a stacked form, which in a row is invisible
 * space that still counts towards the row's height — so the two buttons, which
 * align to the bottom of the row, hung 14px below the boxes beside them.
 *
 * jsdom lays nothing out, but it does resolve the cascade, so these read the
 * settled values off the elements rather than matching text in the file.
 */
describe('the receive line', () => {
    let style;

    /*
     * Once for the file, not once per test: jsdom parses the two stylesheets
     * to answer these, and doing that four times slowed the whole parallel
     * suite enough to time out a heavy test in another file.
     */
    beforeAll(() => {
        document.head.innerHTML = `<style>${readFileSync('resources/css/app.css', 'utf8')}</style>
            <style>${readFileSync('resources/js/Layouts/AdminLayout.css', 'utf8')}</style>`;
        document.body.innerHTML = `
            <div class="admin-receive-line">
                <div class="admin-receive-line-product">
                    <label class="auth-label">Product</label>
                    <button class="ui-select-trigger auth-text-input">Choose a product…</button>
                </div>
                <div class="auth-form-group">
                    <label class="auth-label">Qty</label>
                    <div class="auth-input-wrapper"><input class="auth-text-input"></div>
                </div>
                <button class="admin-receive-line-serials">#</button>
                <button class="admin-receive-line-remove">bin</button>
            </div>`;

        const of = (selector) =>
            getComputedStyle(document.querySelector(selector));

        style = {
            line: of('.admin-receive-line'),
            product: of('.admin-receive-line-product'),
            field: of('.auth-form-group'),
            input: of('.auth-form-group .auth-text-input'),
            serials: of('.admin-receive-line-serials'),
            bin: of('.admin-receive-line-remove'),
        };
    });

    it('has a column for every cell in it', () => {
        const columns = style.line.gridTemplateColumns;

        // minmax(...) counts as one column, whatever commas it holds inside.
        const cells = columns
            .replace(/minmax\([^)]*\)/g, 'x')
            .trim()
            .split(/\s+/);
        expect(cells).toHaveLength(5);
    });

    /** Both buttons end where the boxes end, and are the height of one. */
    it('gives the buttons the height of the inputs beside them', () => {
        const { input, serials, bin } = style;

        expect(input.height).toBe('46px');
        expect(serials.height).toBe(input.height);
        expect(bin.height).toBe(input.height);
        expect(serials.alignSelf).toBe('end');
        expect(bin.alignSelf).toBe('end');
    });

    /** The row ends at the inputs: no stacked-form margin hanging below them. */
    it('leaves no dead space under the fields', () => {
        expect(style.field.marginBottom).toBe('0px');
    });

    /** The label's own margin does the spacing; a gap on top of it double-spaced. */
    it('spaces the product label like every other label', () => {
        const { product } = style;

        expect(product.gap === '' || product.gap === 'normal').toBe(true);
    });
});
