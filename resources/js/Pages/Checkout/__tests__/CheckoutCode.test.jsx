import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
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
        stock_quantity: 5,
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

/** An ApiError as axiosInstance builds one. */
const apiError = (message, { code, errors = {}, data = null } = {}) =>
    Object.assign(new Error(message), {
        code,
        data,
        fieldError: (field) => errors[field] ?? null,
    });

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

const dialog = () => screen.findByRole('dialog');

describe('Checkout, for a guest', () => {
    /*
     * The code step used to sit inside the delivery form, between the number
     * and the address. It is a window over the form now, and the form behind
     * it keeps what was typed.
     */
    it('asks for the code in a window, not in the form, and places the order only with it', async () => {
        services.otpService.forCheckout.mockResolvedValue({ resend_in: 60 });
        services.checkoutService.processCheckout.mockResolvedValue({
            order_number: 'ORD-NEW0000001',
            signed_in: true,
        });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();
        await confirmOnPage();

        const modal = await dialog();
        expect(
            within(modal).getByText('Confirm your mobile number'),
        ).toBeInTheDocument();
        expect(services.otpService.forCheckout).toHaveBeenCalledWith(
            '01712345678',
            '',
        );
        expect(services.checkoutService.processCheckout).not.toHaveBeenCalled();

        // Not in the form itself.
        expect(
            screen.getByPlaceholderText(/House 12, Road 5/).closest('form'),
        ).not.toContainElement(
            within(modal).getByLabelText(/Verification code/),
        );

        await userEvent.type(
            within(modal).getByLabelText(/Verification code/),
            '482913',
        );
        await userEvent.click(
            within(modal).getByRole('button', { name: 'Confirm Order' }),
        );

        await waitFor(() =>
            expect(
                services.checkoutService.processCheckout,
            ).toHaveBeenCalledWith(
                expect.objectContaining({
                    phone: '01712345678',
                    address: 'House 12, Road 4, Dhanmondi, Dhaka',
                    code: '482913',
                    email: null,
                }),
            ),
        );
        expect(router.visit).toHaveBeenCalled();
    });

    it('will not place the order without all six digits', async () => {
        services.otpService.forCheckout.mockResolvedValue({ resend_in: 60 });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();
        await confirmOnPage();

        const modal = await dialog();
        await userEvent.type(
            within(modal).getByLabelText(/Verification code/),
            '4829',
        );
        await userEvent.click(
            within(modal).getByRole('button', { name: 'Confirm Order' }),
        );

        expect(
            await within(modal).findByText(
                'Enter the six-digit code we sent you.',
            ),
        ).toBeInTheDocument();
        expect(services.checkoutService.processCheckout).not.toHaveBeenCalled();
    });

    it('keeps the window open on a wrong code, with the reason', async () => {
        services.otpService.forCheckout.mockResolvedValue({ resend_in: 60 });
        services.checkoutService.processCheckout.mockRejectedValue(
            apiError('That code is not right. 4 tries left.', {
                code: 'VALIDATION_ERROR',
                errors: { code: 'That code is not right. 4 tries left.' },
            }),
        );

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();
        await confirmOnPage();

        const modal = await dialog();
        await userEvent.type(
            within(modal).getByLabelText(/Verification code/),
            '000000',
        );
        await userEvent.click(
            within(modal).getByRole('button', { name: 'Confirm Order' }),
        );

        expect(
            await within(modal).findByText(
                'That code is not right. 4 tries left.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(router.reload).not.toHaveBeenCalled();
    });

    /*
     * A number that already has an account with a password signs in with it.
     * The server sends no code for it, and the window opens on the password.
     */
    it('asks a registered number for its password instead of texting a code', async () => {
        services.otpService.forCheckout.mockRejectedValue(
            apiError(
                'This number already has an account. Sign in with its password.',
                {
                    code: 'SIGN_IN_WITH_PASSWORD',
                    data: { sign_in: { login: '01712345678' } },
                },
            ),
        );
        services.checkoutService.signIn.mockResolvedValue({ name: 'Karim' });
        services.checkoutService.processCheckout.mockResolvedValue({
            order_number: 'ORD-NEW0000003',
        });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();
        await confirmOnPage();

        const modal = await dialog();
        expect(
            within(modal).getByText('Sign in to continue'),
        ).toBeInTheDocument();
        expect(within(modal).queryByLabelText(/Verification code/)).toBeNull();

        await userEvent.type(
            within(modal).getByLabelText(/Password/),
            'secret-pass-1',
        );
        await userEvent.click(
            within(modal).getByRole('button', { name: 'Sign in & Continue' }),
        );

        await waitFor(() =>
            expect(services.checkoutService.signIn).toHaveBeenCalledWith(
                '01712345678',
                'secret-pass-1',
            ),
        );
        await waitFor(() =>
            expect(services.checkoutService.processCheckout).toHaveBeenCalled(),
        );
        expect(
            services.checkoutService.processCheckout.mock.calls[0][0],
        ).not.toHaveProperty('code');
        expect(services.otpService.forCheckout).toHaveBeenCalledTimes(1);
    });

    it('offers no password shortcut on the code step, which is only for new numbers', async () => {
        services.otpService.forCheckout.mockResolvedValue({ resend_in: 60 });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery();
        await confirmOnPage();

        const modal = await dialog();
        expect(
            within(modal).getByLabelText(/Verification code/),
        ).toBeInTheDocument();
        expect(within(modal).queryByText(/password/i)).toBeNull();
    });
});

describe('Checkout, when the email and the mobile point at different accounts', () => {
    const different = () =>
        apiError(
            'This email and this mobile number belong to different accounts.',
            {
                code: 'ACCOUNT_CHOICE',
                data: { choice: { email_account: true, phone_account: true } },
            },
        );

    it('asks which account the order is for, and sends no code yet', async () => {
        services.otpService.forCheckout.mockRejectedValueOnce(different());

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery({ email: 'rahim@example.com' });
        await confirmOnPage();

        const modal = await dialog();
        expect(
            within(modal).getByText('Which account is this order for?'),
        ).toBeInTheDocument();
        expect(
            within(modal).getByRole('button', { name: /rahim@example\.com/ }),
        ).toBeInTheDocument();
        expect(
            within(modal).getByRole('button', { name: /01712345678/ }),
        ).toBeInTheDocument();
        expect(services.otpService.forCheckout).toHaveBeenCalledTimes(1);
        expect(within(modal).queryByLabelText(/Verification code/)).toBeNull();
    });

    it('picking the email asks for its password, then places the order', async () => {
        services.otpService.forCheckout.mockRejectedValueOnce(different());
        services.checkoutService.signIn.mockResolvedValue({ name: 'Rahim' });
        services.checkoutService.processCheckout.mockResolvedValue({
            order_number: 'ORD-NEW0000004',
        });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery({ email: 'rahim@example.com' });
        await confirmOnPage();

        const modal = await dialog();
        await userEvent.click(
            within(modal).getByRole('button', { name: /rahim@example\.com/ }),
        );

        expect(
            within(modal).getByText('Sign in to continue'),
        ).toBeInTheDocument();
        await userEvent.type(
            within(modal).getByLabelText(/Password/),
            'secret-pass-1',
        );
        await userEvent.click(
            within(modal).getByRole('button', { name: 'Sign in & Continue' }),
        );

        await waitFor(() =>
            expect(services.checkoutService.processCheckout).toHaveBeenCalled(),
        );
        expect(services.checkoutService.signIn).toHaveBeenCalledWith(
            'rahim@example.com',
            'secret-pass-1',
        );
        expect(router.reload).toHaveBeenCalled();
        expect(
            services.checkoutService.processCheckout.mock.calls[0][0],
        ).toEqual(expect.objectContaining({ email: 'rahim@example.com' }));
    });

    it('shows a wrong password in the window and places nothing', async () => {
        services.otpService.forCheckout.mockRejectedValueOnce(different());
        services.checkoutService.signIn.mockRejectedValue(
            apiError('Invalid email/mobile number or password.', {
                code: 'VALIDATION_ERROR',
                errors: { login: 'Invalid email/mobile number or password.' },
            }),
        );

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery({ email: 'rahim@example.com' });
        await confirmOnPage();

        const modal = await dialog();
        await userEvent.click(
            within(modal).getByRole('button', { name: /rahim@example\.com/ }),
        );
        await userEvent.type(within(modal).getByLabelText(/Password/), 'nope');
        await userEvent.click(
            within(modal).getByRole('button', { name: 'Sign in & Continue' }),
        );

        expect(
            await within(modal).findByText(
                'Invalid email/mobile number or password.',
            ),
        ).toBeInTheDocument();
        expect(services.checkoutService.processCheckout).not.toHaveBeenCalled();
        expect(router.reload).not.toHaveBeenCalled();
    });

    /*
     * The account had things saved in its own cart, and signing in brought
     * them into this one. Placing the order straight away would sell the
     * customer something they never saw on the page.
     */
    it('stops for a look when signing in changed the cart', async () => {
        services.otpService.forCheckout.mockRejectedValueOnce(different());
        services.checkoutService.signIn.mockResolvedValue({ name: 'Rahim' });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery({ email: 'rahim@example.com' });
        await confirmOnPage();

        const modal = await dialog();
        await userEvent.click(
            within(modal).getByRole('button', { name: /rahim@example\.com/ }),
        );
        await userEvent.type(
            within(modal).getByLabelText(/Password/),
            'secret-pass-1',
        );

        services.cartService.getCart.mockResolvedValue(
            cartOf(line(9), line(12)),
        );
        await userEvent.click(
            within(modal).getByRole('button', { name: 'Sign in & Continue' }),
        );

        await waitFor(() => expect(toast.info).toHaveBeenCalled());
        expect(services.checkoutService.processCheckout).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(await screen.findByText('Part 12')).toBeInTheDocument();
    });

    /*
     * The report this was written for: both registered, the mobile picked,
     * and a code texted anyway. A mobile with a password is asked for it.
     */
    it('picking a mobile that has a password asks for it, and texts nothing', async () => {
        services.otpService.forCheckout.mockRejectedValueOnce(
            apiError(
                'This email and this mobile number belong to different accounts.',
                {
                    code: 'ACCOUNT_CHOICE',
                    data: {
                        choice: {
                            email_account: true,
                            phone_account: true,
                            phone_has_password: true,
                        },
                    },
                },
            ),
        );

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery({ email: 'rahim@example.com' });
        await confirmOnPage();

        const modal = await dialog();
        expect(
            within(modal).getByRole('button', { name: /01712345678/ }),
        ).toHaveTextContent("Sign in with this account's password");

        await userEvent.click(
            within(modal).getByRole('button', { name: /01712345678/ }),
        );

        expect(
            within(modal).getByText('Sign in to continue'),
        ).toBeInTheDocument();
        expect(within(modal).getByText('01712345678')).toBeInTheDocument();
        expect(services.otpService.forCheckout).toHaveBeenCalledTimes(1);
        expect(screen.getByPlaceholderText('you@example.com')).toHaveValue('');

        // Back to the choice puts the email back, and offers it again.
        await userEvent.click(
            within(modal).getByRole('button', { name: 'Back' }),
        );

        expect(
            within(modal).getByRole('button', { name: /rahim@example\.com/ }),
        ).toBeInTheDocument();
        expect(screen.getByPlaceholderText('you@example.com')).toHaveValue(
            'rahim@example.com',
        );
    });

    it('picking the mobile leaves the email off and texts a code', async () => {
        services.otpService.forCheckout
            .mockRejectedValueOnce(different())
            .mockResolvedValue({ resend_in: 60 });
        services.checkoutService.processCheckout.mockResolvedValue({
            order_number: 'ORD-NEW0000005',
        });

        render(<Checkout verifyPhone deliveryRates={rates} />);
        await fillInDelivery({ email: 'rahim@example.com' });
        await confirmOnPage();

        const modal = await dialog();
        await userEvent.click(
            within(modal).getByRole('button', { name: /01712345678/ }),
        );

        await waitFor(() =>
            expect(services.otpService.forCheckout).toHaveBeenLastCalledWith(
                '01712345678',
                null,
            ),
        );
        expect(screen.getByPlaceholderText('you@example.com')).toHaveValue('');

        const codeStep = await dialog();
        await userEvent.type(
            within(codeStep).getByLabelText(/Verification code/),
            '482913',
        );
        await userEvent.click(
            within(codeStep).getByRole('button', { name: 'Confirm Order' }),
        );

        await waitFor(() =>
            expect(
                services.checkoutService.processCheckout,
            ).toHaveBeenCalledWith(
                expect.objectContaining({ email: null, code: '482913' }),
            ),
        );
    });
});

describe('Checkout, signed in', () => {
    it('asks for no code, opens no window, and sends the email it was given', async () => {
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
        await confirmOnPage();

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
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
