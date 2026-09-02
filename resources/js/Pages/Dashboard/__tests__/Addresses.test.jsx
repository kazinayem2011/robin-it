import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

/*
 * The page pulls in the account shell, Inertia's router and the address modal.
 * None of them are what is under test here, and the shell alone would drag in
 * the whole layout — so they are stubbed down to what this file touches.
 */
vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    Head: () => null,
    Link: ({ children }) => <span>{children}</span>,
    usePage: () => ({ props: {}, url: '/dashboard/addresses' }),
}));

vi.mock('../AccountLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));

vi.mock('../AddressFormModal', () => ({
    default: ({ showAddressModal }) =>
        showAddressModal ? <div data-testid="address-modal" /> : null,
}));

vi.mock('../../../Layouts/MainLayout', () => ({ mainLayout: (page) => page }));

const { default: Addresses } = await import('../Addresses');

/**
 * "Add New Address" opened nothing.
 *
 * The click handler called addressForm.reset(). The form is a Formik bag, whose
 * method is resetForm() — reset() belongs to Inertia's useForm — so the handler
 * threw on its first line and the modal was never opened. Nothing caught it,
 * so the button simply did nothing and the console carried the reason.
 */
describe('Addresses — add a new one', () => {
    const user = { name: 'Nayem', phone: '01711111111' };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('opens the form without throwing', async () => {
        const person = userEvent.setup();

        render(<Addresses user={user} navCounts={{}} addresses={[]} />);

        expect(screen.queryByTestId('address-modal')).toBeNull();

        await person.click(
            screen.getByRole('button', { name: /add new address/i }),
        );

        expect(screen.getByTestId('address-modal')).toBeTruthy();
    });

    /**
     * The specific mistake, named. A future edit reaching for the Inertia API on
     * a Formik bag fails here rather than in somebody's browser.
     */
    it('uses the Formik reset, not the Inertia one', async () => {
        const person = userEvent.setup();
        const errors = [];
        const onError = (e) => errors.push(e);
        window.addEventListener('error', onError);

        render(<Addresses user={user} navCounts={{}} addresses={[]} />);

        await person.click(
            screen.getByRole('button', { name: /add new address/i }),
        );

        window.removeEventListener('error', onError);

        expect(
            errors.filter((e) => /is not a function/.test(e.message ?? '')),
        ).toHaveLength(0);
    });
});
