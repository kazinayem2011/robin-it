import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

let pageProps = { auth: { user: { id: 1, phone: '01712345678' } } };

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    usePage: () => ({ props: pageProps }),
}));
vi.mock('@/Layouts/MainLayout', () => ({ mainLayout: (page) => page }));
vi.mock('@/Components/ProductSuggestions', () => ({ default: () => null }));

import Success from '../Success';

describe('The order confirmation page', () => {
    /*
     * Checkout makes a guest an account and signs them in. Nothing said so,
     * and this is the one moment they are certain to be looking.
     */
    it('says an account was made, and where to set a password', () => {
        render(<Success orderNumber="ORD-NEW0000001" accountIsNew />);

        expect(
            screen.getByText(/We have created an account for/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('01712345678', { exact: false }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Set a password' }),
        ).toHaveAttribute('href', '/dashboard/profile');
    });

    /** No password is ever sent, so the page says that too. */
    it('promises no password by text or email', () => {
        render(<Success orderNumber="ORD-NEW0000001" accountIsNew />);

        expect(
            screen.getByText(/never send passwords by\s+text or email/),
        ).toBeInTheDocument();
    });

    it('says nothing to a customer who already had an account', () => {
        render(<Success orderNumber="ORD-NEW0000001" />);

        expect(screen.queryByText(/We have created an account/)).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Set a password' }),
        ).toBeNull();
    });
});
