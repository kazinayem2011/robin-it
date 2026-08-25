import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import MainLayout from '@/Layouts/MainLayout';
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
        <MainLayout>
            <Head title={`${title} — ${siteConfig.name}`} />

            <div className="dashboard-layout-container">
                <div className="container">
                    <div className="dashboard-header-banner">
                        <div className="user-profile-header-left">
                            <div className="user-avatar-disc">
                                {user?.name
                                    ? user.name.charAt(0).toUpperCase()
                                    : 'U'}
                            </div>
                            <div className="user-meta-info">
                                <h1>{user?.name}</h1>
                                <div className="user-contact-pills">
                                    <span>
                                        <Mail size={14} /> {user?.email}
                                    </span>
                                    <span>
                                        <Phone size={14} /> 🇧🇩{' '}
                                        {formatBdPhone(user?.phone)}
                                    </span>
                                    <span>
                                        <ShieldCheck
                                            size={14}
                                            className="text-emerald"
                                        />{' '}
                                        Verified Member
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="user-vip-badge">
                            <Award size={16} />
                            <span>TECHCLUB VIP ({techPoints} PTS)</span>
                        </div>
                    </div>

                    <div className="dashboard-content-grid">
                        <aside className="dashboard-sidebar-card">
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

                        <main className="dashboard-main-surface">
                            {children}
                        </main>
                    </div>
                </div>
            </div>
        </MainLayout>
    );
}
