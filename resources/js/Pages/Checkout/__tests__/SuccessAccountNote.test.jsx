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
    it('says an account was made, and where to change its password', () => {
        render(<Success orderNumber="ORD-NEW0000001" accountIsNew />);

        expect(
            screen.getByText(/We have created an account for/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('01712345678', { exact: false }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Change password' }),
        ).toHaveAttribute('href', '/dashboard/profile');
    });

    /*
     * The password was generated and texted to the number. The page says
     * where it went, and never shows it.
     */
    it('says the password was texted, without showing it', () => {
        render(<Success orderNumber="ORD-NEW0000001" accountIsNew />);

        expect(
            screen.getByText(/texted your\s+password to this number/),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Password:/)).toBeNull();
    });

    it('says nothing to a customer who already had an account', () => {
        render(<Success orderNumber="ORD-NEW0000001" />);

        expect(screen.queryByText(/We have created an account/)).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Change password' }),
        ).toBeNull();
    });
});
