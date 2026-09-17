import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

let pageProps = { auth: { user: null } };

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    usePage: () => ({ props: pageProps }),
}));

vi.mock('@/Layouts/MainLayout', () => ({ mainLayout: (page) => page }));

const toast = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
}));
vi.mock('@/Components/Toast', () => ({ toast }));

const trackOrder = vi.hoisted(() => vi.fn());
vi.mock('@/services', () => ({ orderTrackingService: { trackOrder } }));

import TrackOrder from '../Index';

const order = {
    order_number: 'ORD-LSFCIBTEIG',
    created_at: '17 Sep, 2026 10:00 AM',
    status: 'pending',
    current_step: 1,
    status_label: 'Order Placed',
    status_desc: 'Your order has been received.',
    subtotal: 1000,
    shipping_fee: 60,
    discount: 0,
    total: 1060,
    payment_method: 'COD',
    payment_status: 'unpaid',
    shipping_address: { name: 'Karim', phone: '01712345678' },
    items: [],
};

beforeEach(() => {
    pageProps = { auth: { user: null } };
    trackOrder.mockReset();
    Object.values(toast).forEach((fn) => fn.mockReset());
});

describe('Tracking link', () => {
    /*
     * The link in the order's own text: it used to fill in the order number
     * and then ask a guest for the number the text had been sent to.
     */
    it('opens the order for a guest holding the link, without asking for the phone', async () => {
        trackOrder.mockResolvedValue(order);

        render(
            <TrackOrder orderNumber="ORD-LSFCIBTEIG" accessKey="0a1b2c3d4e" />,
        );

        expect(await screen.findByText(/Showing order/)).toBeInTheDocument();
        expect(trackOrder).toHaveBeenCalledWith(
            'ORD-LSFCIBTEIG',
            '',
            '0a1b2c3d4e',
        );
    });

    it('still waits for the phone when a guest arrives without the key', async () => {
        render(<TrackOrder orderNumber="ORD-LSFCIBTEIG" />);

        expect(
            await screen.findByText(/Enter the mobile number on order/),
        ).toBeInTheDocument();
        expect(trackOrder).not.toHaveBeenCalled();
    });

    /*
     * An old link — the shop's key changed, say — falls back to the form, and
     * the form then wants the number rather than trying the dead key again.
     */
    it('asks for the phone once the key has failed', async () => {
        trackOrder.mockRejectedValue(new Error('not found'));

        render(
            <TrackOrder orderNumber="ORD-LSFCIBTEIG" accessKey="0a1b2c3d4e" />,
        );

        await waitFor(() => expect(toast.info).toHaveBeenCalled());
        expect(trackOrder).toHaveBeenCalledTimes(1);

        await userEvent.click(
            screen.getByRole('button', { name: /TRACK ORDER STATUS/ }),
        );

        expect(
            await screen.findByText('Bangladeshi mobile number is required'),
        ).toBeInTheDocument();
        expect(trackOrder).toHaveBeenCalledTimes(1);
    });

    /*
     * The key is still live after a failure when a phone went with it — a
     * signed-in customer's is filled in for them — so what stops it riding
     * along with the next order typed is the order-number check alone.
     */
    it('does not lend the key to an order typed in afterwards', async () => {
        pageProps = { auth: { user: { id: 1, phone: '01712345678' } } };
        trackOrder
            .mockRejectedValueOnce(new Error('not found'))
            .mockResolvedValue(order);

        render(
            <TrackOrder orderNumber="ORD-LSFCIBTEIG" accessKey="0a1b2c3d4e" />,
        );
        await waitFor(() => expect(trackOrder).toHaveBeenCalledTimes(1));
        expect(trackOrder).toHaveBeenLastCalledWith(
            'ORD-LSFCIBTEIG',
            '01712345678',
            '0a1b2c3d4e',
        );

        const box = screen.getByLabelText(/Order Number/);
        await userEvent.clear(box);
        await userEvent.type(box, 'ORD-SOMEONEELSE');
        await userEvent.click(
            screen.getByRole('button', { name: /TRACK ORDER STATUS/ }),
        );

        await waitFor(() => expect(trackOrder).toHaveBeenCalledTimes(2));
        expect(trackOrder).toHaveBeenLastCalledWith(
            'ORD-SOMEONEELSE',
            '01712345678',
            null,
        );
    });
});
