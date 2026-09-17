import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
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
vi.mock('@/Components/Toast', () => ({
    toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock('@/store/useAppStore', () => ({
    default: { getState: () => ({ fetchCartCount: vi.fn() }) },
}));

const services = vi.hoisted(() => ({
    cartService: { getCart: vi.fn(), updateItemQuantity: vi.fn() },
    checkoutService: { processCheckout: vi.fn() },
    couponService: { applyCoupon: vi.fn() },
    otpService: { forCheckout: vi.fn() },
}));
vi.mock('@/services', () => services);

import Checkout from '../Index';

const cart = {
    items: [
        {
            id: 1,
            quantity: 1,
            product: {
                id: 9,
                name: 'Ryzen 7',
                price: 30000,
                effective_price: 30000,
                stock_quantity: 5,
            },
        },
    ],
    totals: { subtotal: 30000, shipping_fee: 60, discount: 0, total: 30060 },
};

const rates = {
    zones: { inside_dhaka: 60, outside_dhaka: 120 },
    free_over: null,
};

beforeEach(() => {
    services.cartService.getCart.mockResolvedValue(cart);
    services.checkoutService.processCheckout.mockReset();
    services.otpService.forCheckout.mockReset();
    router.visit.mockReset();
});

const fillInDelivery = async () => {
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
};

describe('Checkout, for a guest', () => {
    it('texts a code first, and places the order only with it', async () => {
        services.otpService.forCheckout.mockResolvedValue({ resend_in: 60 });
        services.checkoutService.processCheckout.mockResolvedValue({
            order_number: 'ORD-NEW0000001',
            signed_in: true,
        });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();

        await userEvent.click(
            screen.getByRole('button', { name: 'Send Code & Continue' }),
        );

        await waitFor(() =>
            expect(services.otpService.forCheckout).toHaveBeenCalledWith(
                '01712345678',
                '',
            ),
        );
        expect(services.checkoutService.processCheckout).not.toHaveBeenCalled();

        await userEvent.type(
            await screen.findByLabelText(/Verification code/),
            '482913',
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Confirm Order' }),
        );

        await waitFor(() =>
            expect(
                services.checkoutService.processCheckout,
            ).toHaveBeenCalledTimes(1),
        );
        expect(services.checkoutService.processCheckout).toHaveBeenCalledWith(
            expect.objectContaining({
                phone: '01712345678',
                code: '482913',
                email: null,
            }),
        );
        expect(router.visit).toHaveBeenCalled();
    });

    it('will not place the order without all six digits', async () => {
        services.otpService.forCheckout.mockResolvedValue({ resend_in: 60 });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();
        await userEvent.click(
            screen.getByRole('button', { name: 'Send Code & Continue' }),
        );

        await userEvent.type(
            await screen.findByLabelText(/Verification code/),
            '4829',
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Confirm Order' }),
        );

        expect(
            await screen.findByText('Enter the six-digit code we sent you.'),
        ).toBeInTheDocument();
        expect(services.checkoutService.processCheckout).not.toHaveBeenCalled();
    });
});

describe('Checkout, when the email has an account of its own', () => {
    const taken =
        'This email already has an account. Sign in to order with it, or leave the email blank.';

    const refusal = () =>
        Object.assign(new Error(taken), {
            code: 'VALIDATION_ERROR',
            fieldError: (field) => (field === 'email' ? taken : null),
        });

    it('says so under the email, offers a way to sign in, and sends no code', async () => {
        services.otpService.forCheckout.mockRejectedValue(refusal());

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();
        await userEvent.type(
            screen.getByPlaceholderText('you@example.com'),
            'karim@example.com',
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Send Code & Continue' }),
        );

        expect(await screen.findByText(taken)).toBeInTheDocument();
        expect(services.otpService.forCheckout).toHaveBeenCalledWith(
            '01712345678',
            'karim@example.com',
        );
        expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute(
            'href',
            '/login?redirect=%2Fcheckout',
        );
        // Still the first step: nothing was texted.
        expect(screen.queryByLabelText(/Verification code/)).toBeNull();
    });

    /*
     * The objection is about that address. Typing elsewhere leaves it up —
     * Formik's own errors are rewritten on every keystroke, which is why it is
     * not kept there — and changing the address takes it down.
     */
    it('keeps the message until the email itself changes', async () => {
        services.otpService.forCheckout.mockRejectedValue(refusal());

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();
        const email = screen.getByPlaceholderText('you@example.com');
        await userEvent.type(email, 'karim@example.com');
        await userEvent.click(
            screen.getByRole('button', { name: 'Send Code & Continue' }),
        );
        await screen.findByText(taken);

        await userEvent.type(
            screen.getByPlaceholderText('e.g. Rahim Chowdhury'),
            'x',
        );
        expect(screen.getByText(taken)).toBeInTheDocument();

        await userEvent.clear(email);
        expect(screen.queryByText(taken)).toBeNull();
    });
});

describe('Checkout, signed in', () => {
    it('asks for no code, and sends the email it was given', async () => {
        services.checkoutService.processCheckout.mockResolvedValue({
            order_number: 'ORD-NEW0000002',
        });

        render(
            <Checkout
                deliveryRates={rates}
                contact={{
                    name: 'Karim Uddin',
                    phone: '01712345678',
                    email: 'karim@example.com',
                }}
            />,
        );

        await userEvent.type(
            await screen.findByPlaceholderText(/House 12, Road 5/),
            'House 12, Road 4, Dhanmondi, Dhaka',
        );
        await userEvent.click(screen.getByLabelText(/Inside Dhaka/));
        await userEvent.click(
            screen.getByRole('button', { name: 'Confirm Order' }),
        );

        await waitFor(() =>
            expect(
                services.checkoutService.processCheckout,
            ).toHaveBeenCalledTimes(1),
        );

        const payload =
            services.checkoutService.processCheckout.mock.calls[0][0];
        expect(payload.email).toBe('karim@example.com');
        expect(payload).not.toHaveProperty('code');
        expect(services.otpService.forCheckout).not.toHaveBeenCalled();
    });
});
