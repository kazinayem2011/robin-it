import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    User,
    Package,
    Heart,
    MapPin,
    LogOut,
    ShieldCheck,
    Sparkles,
    Award,
    Phone,
    Mail,
    ChevronRight,
} from 'lucide-react';
import { formatBdPhone } from '@/utils/formatters';
import AvatarUploader from './AvatarUploader';
import siteConfig from '@/constants/siteConfig';
import { ROUTES } from '@/constants/endpoints';
import './Dashboard.css';

/**
 * The frame every account page sits in: the member header and the section nav.
 *
 * The account used to be one 1,200-line screen switching tabs in local state,
 * which meant no section had a URL — you could not link to your orders, open
 * them in a tab, or come back to where you were. Each section is its own page
 * now, and this holds the parts they share so the sidebar exists once.
 */
export default function AccountLayout({
    title,
    active,
    user,
    navCounts = {},
    techPoints = 0,
    children,
}) {
    const { url } = usePage();

    const items = [
        {
            key: 'overview',
            label: 'Overview',
            icon: Sparkles,
            href: ROUTES.DASHBOARD,
        },
        {
            key: 'orders',
            label: 'My Orders',
            icon: Package,
            href: ROUTES.DASHBOARD_ORDERS,
            count: navCounts.orders,
        },
        {
            key: 'wishlist',
            label: 'Wishlist',
            icon: Heart,
            href: ROUTES.DASHBOARD_WISHLIST,
            count: navCounts.wishlist,
        },
        {
            key: 'addresses',
            label: 'Delivery Addresses',
            icon: MapPin,
            href: ROUTES.DASHBOARD_ADDRESSES,
        },
        {
            key: 'profile',
            label: 'Profile & Security',
            icon: User,
            href: ROUTES.DASHBOARD_PROFILE,
        },
    ];

    // Fall back to the URL so the right link is marked even if a page forgets
    // to say which one it is.
    const current =
        active || items.find((i) => i.href === url)?.key || 'overview';

    return (
        <>
            <Head title={`${title} — ${siteConfig.name}`} />

            <div className="dashboard-layout-container">
                <div className="container">
                    {/*
                     * The member details used to sit in a full-width banner
                     * above everything, which pushed the section itself below
                     * the fold on a laptop. They belong beside the nav they
                     * relate to, so the content starts at the top of the page.
                     */}
                    <div className="dashboard-content-grid">
                        {/*
                         * The column stretches to the row's height so the card
                         * inside it has somewhere to travel. A sticky element
                         * whose own grid area is only as tall as itself has no
                         * room to move and simply scrolls away.
                         */}
                        <div className="dashboard-sidebar-col">
                            <aside className="dashboard-sidebar-card">
                                <div className="account-identity">
                                    <AvatarUploader user={user} />

                                    <h1 className="account-identity-name">
                                        {user?.name}
                                    </h1>

                                    <span className="account-identity-badge">
                                        <Award size={13} />
                                        {techPoints} pts
                                    </span>

                                    <ul className="account-identity-meta">
                                        <li title={user?.email}>
                                            <Mail size={13} />
                                            <span>{user?.email}</span>
                                        </li>
                                        <li>
                                            <Phone size={13} />
                                            <span>
                                                {formatBdPhone(user?.phone) ||
                                                    'No number yet'}
                                            </span>
                                        </li>
                                        <li className="is-verified">
                                            <ShieldCheck size={13} />
                                            <span>Verified member</span>
                                        </li>
                                    </ul>
                                </div>

                                <ul className="dash-nav-menu">
                                    {items.map(
                                        ({
                                            key,
                                            label,
                                            icon: Icon,
                                            href,
                                            count,
                                        }) => (
                                            <li key={key}>
                                                <Link
                                                    href={href}
                                                    className={`dash-nav-item-btn ${current === key ? 'active-tab' : ''}`}
                                                    aria-current={
                                                        current === key
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                >
                                                    <div className="dash-nav-left">
                                                        <Icon size={18} />
                                                        <span>{label}</span>
                                                    </div>
                                                    {count > 0 && (
                                                        <span className="dash-nav-badge">
                                                            {count}
                                                        </span>
                                                    )}
                                                </Link>
                                            </li>
                                        ),
                                    )}

                                    {user?.role === 'admin' && (
                                        <li className="dash-admin-nav-item">
                                            <Link
                                                href={ROUTES.ADMIN_DASHBOARD}
                                                className="dash-nav-item-btn dash-admin-link"
                                            >
                                                <div className="dash-nav-left">
                                                    <ShieldCheck size={18} />
                                                    <span>Admin Console</span>
                                                </div>
                                                <ChevronRight size={16} />
                                            </Link>
                                        </li>
                                    )}

                                    <li className="dash-logout-li">
                                        <button
                                            type="button"
                                            className="dash-nav-item-btn dash-logout-btn"
                                            onClick={() =>
                                                router.post(ROUTES.LOGOUT)
                                            }
                                        >
                                            <div className="dash-nav-left">
                                                <LogOut size={18} />
                                                <span>Sign Out</span>
                                            </div>
                                        </button>
                                    </li>
                                </ul>
                            </aside>
                        </div>

                        <main className="dashboard-main-surface">
                            {children}
                        </main>
                    </div>
                </div>
            </div>
        </>
    );
}
