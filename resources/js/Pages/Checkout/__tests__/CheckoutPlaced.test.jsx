import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const router = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router,
    usePage: () => ({ props: {} }),
}));

vi.mock('@/Layouts/MainLayout', () => ({ mainLayout: (page) => page }));

const toast = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
}));
vi.mock('@/Components/Toast', () => ({ toast }));
vi.mock('@/store/useAppStore', () => ({
    default: { getState: () => ({ fetchCartCount: vi.fn() }) },
}));

const services = vi.hoisted(() => ({
    cartService: { getCart: vi.fn(), updateItemQuantity: vi.fn() },
    checkoutService: { processCheckout: vi.fn(), signIn: vi.fn() },
    couponService: { applyCoupon: vi.fn() },
    otpService: { forCheckout: vi.fn() },
}));
vi.mock('@/services', () => services);

import Checkout from '../Index';

const line = (productId, quantity = 1) => ({
    id: productId,
    quantity,
    product: {
        id: productId,
        name: `Part ${productId}`,
        price: 30000,
        effective_price: 30000,
        in_stock: true,
    },
});

const cartOf = (...items) => ({
    items,
    totals: { subtotal: 30000, shipping_fee: 60, discount: 0, total: 30060 },
});

const rates = {
    zones: { inside_dhaka: 60, outside_dhaka: 120 },
    free_over: null,
};

beforeEach(() => {
    services.cartService.getCart.mockReset().mockResolvedValue(cartOf(line(9)));
    services.checkoutService.processCheckout.mockReset();
    services.checkoutService.signIn.mockReset();
    services.otpService.forCheckout.mockReset();
    router.visit.mockReset();
    router.reload.mockReset();
    Object.values(toast).forEach((fn) => fn.mockReset());
});

const fillInDelivery = async ({ email = '' } = {}) => {
    await userEvent.type(
        await screen.findByPlaceholderText('e.g. Rahim Chowdhury'),
        'Karim Uddin',
    );
    await userEvent.type(
        screen.getByPlaceholderText('01711223344'),
        '01712345678',
    );
    await userEvent.type(
        screen.getByPlaceholderText(/House 12, Road 5/),
        'House 12, Road 4, Dhanmondi, Dhaka',
    );
    await userEvent.click(screen.getByLabelText(/Inside Dhaka/));

    if (email) {
        await userEvent.type(
            screen.getByPlaceholderText('you@example.com'),
            email,
        );
    }
};

const confirmOnPage = () =>
    userEvent.click(screen.getByRole('button', { name: 'Confirm Order' }));

/*
 * On live the move to the confirmation sometimes never finished: the order
 * was placed, the toast said so, and the page sat on checkout with Confirm
 * Order live — an invitation to order twice.
 */
describe('once the order is placed', () => {
    it('says so in place of Confirm Order, and loads the confirmation if the move stalls', async () => {
        services.checkoutService.processCheckout.mockResolvedValue({
            order_number: 'ORD-PLACED0001',
        });
        const assign = vi.fn();
        const original = window.location;
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...original, assign },
        });

        try {
            render(<Checkout verifyPhone={false} deliveryRates={rates} />);
            await fillInDelivery();
            vi.useFakeTimers({ shouldAdvanceTime: true });
            await confirmOnPage();

            const note = await screen.findByRole('status');
            expect(note).toHaveTextContent('Order ORD-PLACED0001 is placed.');
            expect(
                within(note).getByRole('link', { name: 'Open it now' }),
            ).toHaveAttribute(
                'href',
                expect.stringContaining('ORD-PLACED0001'),
            );
            expect(
                screen.queryByRole('button', { name: 'Confirm Order' }),
            ).toBeNull();
            expect(router.visit).toHaveBeenCalledWith(
                expect.stringContaining('ORD-PLACED0001'),
            );

            // The visit never lands: after a few seconds, a full load.
            expect(assign).not.toHaveBeenCalled();
            await vi.advanceTimersByTimeAsync(8000);
            expect(assign).toHaveBeenCalledWith(
                expect.stringContaining('ORD-PLACED0001'),
            );
            expect(
                services.checkoutService.processCheckout,
            ).toHaveBeenCalledTimes(1);
        } finally {
            vi.useRealTimers();
            Object.defineProperty(window, 'location', {
                configurable: true,
                value: original,
            });
        }
    });
});
