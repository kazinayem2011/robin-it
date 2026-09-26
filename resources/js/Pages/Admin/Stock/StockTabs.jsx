import React from 'react';
import { Link } from '@inertiajs/react';
import { ROUTES } from '@/constants/endpoints';

/**
 * The tabs across the top of the stock pages, and of Purchases.
 *
 * The stock cycle was seven menu items — Stock & Inventory, Stock Take,
 * Adjustments, Serial Numbers, Notify-me Requests, Purchasing, Suppliers —
 * and whoever ran the shop had to know which held what. Now the menu has
 * two, Stock and Purchases, and what used to be separate items are tabs
 * inside them, each page saying where it sits among the others.
 */
const STOCK_TABS = [
    { href: ROUTES.ADMIN_STOCK, label: 'Stock' },
    { href: ROUTES.ADMIN_STOCK_ADJUSTMENTS, label: 'History' },
    { href: ROUTES.ADMIN_STOCK_COUNT, label: 'Stock count' },
    { href: ROUTES.ADMIN_STOCK_SERIALS, label: 'Serial numbers' },
    { href: ROUTES.ADMIN_STOCK_REQUESTS, label: 'Notify-me' },
];

const PURCHASE_TABS = [
    { href: ROUTES.ADMIN_PURCHASING, label: 'Purchase orders' },
    { href: ROUTES.ADMIN_SUPPLIERS, label: 'Suppliers' },
];

function PageTabs({ tabs, current, label }) {
    return (
        <nav className="admin-page-tabs" aria-label={label}>
            {tabs.map((t) => (
                <Link
                    key={t.href}
                    href={t.href}
                    className={`admin-page-tab${t.href === current ? ' is-active' : ''}`}
                    aria-current={t.href === current ? 'page' : undefined}
                >
                    {t.label}
                </Link>
            ))}
        </nav>
    );
}

/** @param {string} current the tab this page is (one of ROUTES) */
export function StockTabs({ current }) {
    return <PageTabs tabs={STOCK_TABS} current={current} label="Stock" />;
}

export function PurchaseTabs({ current }) {
    return (
        <PageTabs tabs={PURCHASE_TABS} current={current} label="Purchases" />
    );
}

export default StockTabs;
