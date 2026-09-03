import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    Sparkles,
    Package,
    Heart,
    MapPin,
    User,
    Bell,
    LayoutDashboard,
    ExternalLink,
    LogOut,
    ChevronDown,
} from 'lucide-react';
import { ROUTES } from '../constants/endpoints';
import './UserMenu.css';

/**
 * The account menu, in both headers.
 *
 * On the storefront the avatar was a plain link to the dashboard overview, so
 * reaching an address or an order meant landing on one page and navigating
 * from it. In the admin there was no account control in the topbar at all —
 * signing out was a small icon in the sidebar footer, which is also the part
 * that collapses on a phone.
 *
 * One component for both, because two would diverge: a link added for staff
 * and forgotten for customers is how a menu ends up different in each place
 * for no reason anybody can remember.
 *
 * @param user     the signed-in person
 * @param variant  'site' in the storefront header, 'admin' in the topbar —
 *                 which decides the trigger's shape, not what it contains
 */
export default function UserMenu({ user, variant = 'site' }) {
    const [open, setOpen] = useState(false);
    const wrapper = useRef(null);

    const staff = Boolean(user?.role) && user.role !== 'customer';

    const close = useCallback(() => setOpen(false), []);

    // A click anywhere else closes it, the way every other menu here behaves.
    useEffect(() => {
        if (!open) return undefined;

        const onDown = (e) => {
            if (!wrapper.current?.contains(e.target)) close();
        };

        const onKey = (e) => {
            if (e.key === 'Escape') close();
        };

        document.addEventListener('mousedown', onDown);
        window.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onDown);
            window.removeEventListener('keydown', onKey);
        };
    }, [open, close]);

    if (!user) return null;

    /*
     * What this person actually has.
     *
     * A customer's account sections, and for staff the admin they work in —
     * plus the store itself, which is the thing they are most often checking
     * against. Notifications is in both because both receive them.
     */
    const links = staff
        ? [
              {
                  href: ROUTES.ADMIN_DASHBOARD,
                  label: 'Admin dashboard',
                  icon: LayoutDashboard,
              },
              {
                  href: ROUTES.NOTIFICATIONS,
                  label: 'Notifications',
                  icon: Bell,
              },
              {
                  href: ROUTES.DASHBOARD_PROFILE,
                  label: 'Profile & security',
                  icon: User,
              },
              {
                  href: ROUTES.HOME,
                  label: 'Open the store',
                  icon: ExternalLink,
                  newTab: true,
              },
          ]
        : [
              { href: ROUTES.DASHBOARD, label: 'Overview', icon: Sparkles },
              {
                  href: ROUTES.DASHBOARD_ORDERS,
                  label: 'My orders',
                  icon: Package,
              },
              {
                  href: ROUTES.DASHBOARD_WISHLIST,
                  label: 'Wishlist',
                  icon: Heart,
              },
              {
                  href: ROUTES.DASHBOARD_ADDRESSES,
                  label: 'Delivery addresses',
                  icon: MapPin,
              },
              {
                  href: ROUTES.NOTIFICATIONS,
                  label: 'Notifications',
                  icon: Bell,
              },
              {
                  href: ROUTES.DASHBOARD_PROFILE,
                  label: 'Profile & security',
                  icon: User,
              },
          ];

    const initial = user.name?.charAt(0).toUpperCase() ?? '?';

    return (
        <div
            className={`user-menu variant-${variant}`}
            data-open={open}
            ref={wrapper}
        >
            <button
                type="button"
                className="user-menu-trigger"
                aria-haspopup="menu"
                aria-expanded={open}
                /*
                 * Named for a screen reader, since nothing here is written
                 * down any more: the trigger is the avatar alone, and the name
                 * it used to carry is in the panel, in full, where it is
                 * actually useful.
                 */
                aria-label={`Account menu for ${user.name}`}
                onClick={() => setOpen((was) => !was)}
                title={user.name}
            >
                <span className={`user-menu-avatar ${staff ? 'is-staff' : ''}`}>
                    {/* The picture where there is one; the initial is the
                        fallback, not the rule. */}
                    {user.avatar ? (
                        <img src={user.avatar} alt={user.name || 'Profile'} />
                    ) : (
                        initial
                    )}
                </span>

                <ChevronDown size={14} className="user-menu-chevron" />
            </button>

            {open && (
                <div className="user-menu-panel" role="menu">
                    {/*
                     * The whole name and what this account is, once, at the
                     * top. The trigger only has room for a first name, and two
                     * staff called Rahim need more than that to know which
                     * account they are signed in as.
                     */}
                    <div className="user-menu-head">
                        <strong>{user.name}</strong>
                        <span>
                            {user.email ||
                                user.phone ||
                                user.role_label ||
                                'Signed in'}
                        </span>
                    </div>

                    <ul className="user-menu-list">
                        {links.map(({ href, label, icon: Icon, newTab }) => (
                            <li key={label}>
                                <Link
                                    href={href}
                                    role="menuitem"
                                    onClick={close}
                                    {...(newTab
                                        ? {
                                              target: '_blank',
                                              rel: 'noopener noreferrer',
                                          }
                                        : {})}
                                >
                                    <Icon size={15} />
                                    <span>{label}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>

                    {/* Its own group, and the only destructive thing here, so
                        it is not one slip away from "Notifications". */}
                    <button
                        type="button"
                        role="menuitem"
                        className="user-menu-signout"
                        onClick={() => {
                            close();
                            router.post(ROUTES.LOGOUT);
                        }}
                    >
                        <LogOut size={15} />
                        <span>Sign out</span>
                    </button>
                </div>
            )}
        </div>
    );
}
