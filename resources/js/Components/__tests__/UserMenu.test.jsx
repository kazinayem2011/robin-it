import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { post: (...a) => post(...a) },
    Link: ({ children, href, onClick, ...rest }) => (
        <a href={href} onClick={onClick} {...rest}>
            {children}
        </a>
    ),
}));

const { default: UserMenu } = await import('../UserMenu');

/**
 * The account menu, in both headers.
 *
 * On the storefront the avatar was a plain link to the dashboard overview; in
 * the admin the topbar had no account control at all, and signing out was an
 * icon in the sidebar footer — the part that collapses into a drawer on a
 * phone.
 *
 * One component serves both, which is the point of these: what a customer is
 * offered and what staff are offered must not quietly drift apart.
 */
describe('UserMenu', () => {
    const customer = {
        name: 'Rahim Chowdhury',
        email: 'rahim@example.com',
        role: 'customer',
    };

    const staff = {
        name: 'Nayem Kazi',
        email: 'nayem@example.com',
        role: 'admin',
        role_label: 'Administrator',
    };

    beforeEach(() => vi.clearAllMocks());

    const openMenu = async (user, variant = 'site') => {
        const person = userEvent.setup();
        render(<UserMenu user={user} variant={variant} />);
        await person.click(
            screen.getByRole('button', { name: /rahim|nayem/i }),
        );

        return person;
    };

    it('shows nothing at all when nobody is signed in', () => {
        const { container } = render(<UserMenu user={null} />);

        expect(container).toBeEmptyDOMElement();
    });

    /*
     * The trigger is the avatar alone. The name used to sit beside it and was
     * saying the same thing twice — the panel gives it in full, with the
     * address, which is the version worth having.
     */
    it('does not write the name beside the avatar', () => {
        render(<UserMenu user={customer} />);

        expect(screen.queryByText(/rahim/i)).toBeNull();
    });

    /* Nothing is written down there any more, so a screen reader needs the
       button named some other way. */
    it('still names the trigger for a screen reader', () => {
        render(<UserMenu user={customer} />);

        expect(
            screen.getByRole('button', {
                name: /account menu for rahim chowdhury/i,
            }),
        ).toBeTruthy();
    });

    it('stays shut until it is asked for', () => {
        render(<UserMenu user={customer} />);

        expect(screen.queryByRole('menu')).toBeNull();
    });

    it('offers a customer their own account sections', async () => {
        await openMenu(customer);

        for (const label of [
            /overview/i,
            /my orders/i,
            /wishlist/i,
            /delivery addresses/i,
            /notifications/i,
            /profile & security/i,
        ]) {
            expect(screen.getByRole('menuitem', { name: label })).toBeTruthy();
        }
    });

    /* A customer has no admin to be sent to, and offering it would be a link
       to a page that refuses them. */
    it('does not offer a customer the admin', async () => {
        await openMenu(customer);

        expect(
            screen.queryByRole('menuitem', { name: /admin dashboard/i }),
        ).toBeNull();
    });

    /*
     * The menu used to be chosen by role alone, so a staff member standing in
     * the admin was offered the admin — the place they were already — and was
     * never offered the customer dashboard, which nothing inside the admin
     * links to. It reads where it is now.
     */
    it('offers staff the admin when they are on the store', async () => {
        await openMenu(staff, 'site');

        expect(
            screen.getByRole('menuitem', { name: /admin dashboard/i }),
        ).toBeTruthy();
        expect(
            screen.queryByRole('menuitem', { name: /open the store/i }),
        ).toBeNull();
    });

    it('offers staff the store when they are in the admin', async () => {
        await openMenu(staff, 'admin');

        expect(
            screen.getByRole('menuitem', { name: /open the store/i }),
        ).toBeTruthy();
        expect(
            screen.queryByRole('menuitem', { name: /admin dashboard/i }),
        ).toBeNull();
    });

    /* Their own orders and addresses live on the customer side, and nothing
       inside the admin links to them. */
    it('offers staff their own account from inside the admin', async () => {
        await openMenu(staff, 'admin');

        for (const label of [
            /my orders/i,
            /wishlist/i,
            /delivery addresses/i,
        ]) {
            expect(screen.getByRole('menuitem', { name: label })).toBeTruthy();
        }
    });

    /* The trigger has room for a first name; two staff called Rahim need the
       whole name and the address to know which account they are in. */
    it('names the account in full once it is open', async () => {
        await openMenu(staff);

        expect(screen.getByText('Nayem Kazi')).toBeTruthy();
        expect(screen.getByText('nayem@example.com')).toBeTruthy();
    });

    it('signs out by posting, never by following a link', async () => {
        const person = await openMenu(customer);

        await person.click(screen.getByRole('menuitem', { name: /sign out/i }));

        expect(post).toHaveBeenCalledWith('/logout');
    });

    it('closes when Escape is pressed', async () => {
        const person = await openMenu(customer);

        expect(screen.getByRole('menu')).toBeTruthy();

        await person.keyboard('{Escape}');

        expect(screen.queryByRole('menu')).toBeNull();
    });

    it('closes when something outside it is clicked', async () => {
        const person = userEvent.setup();
        render(
            <div>
                <UserMenu user={customer} />
                <button type="button">elsewhere</button>
            </div>,
        );

        await person.click(screen.getByRole('button', { name: /rahim/i }));
        expect(screen.getByRole('menu')).toBeTruthy();

        await person.click(screen.getByRole('button', { name: 'elsewhere' }));
        expect(screen.queryByRole('menu')).toBeNull();
    });

    /* Following a link and leaving the panel open behind it would have it
       hanging over whatever page arrives next. */
    it('closes when a link inside it is followed', async () => {
        const person = await openMenu(customer);

        await person.click(screen.getByRole('menuitem', { name: /wishlist/i }));

        expect(screen.queryByRole('menu')).toBeNull();
    });
});
