import React from 'react';
import { StockTabs } from './StockTabs';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { BellRing } from 'lucide-react';
import DataTable from '@/Components/DataTable';
import Tabs from '@/Components/Tabs';
import { ROUTES } from '@/constants/endpoints';
import './Count.css';

/**
 * Customers waiting to hear that something sold out is back.
 *
 * "Notify me" on a sold-out product took an email and sent it the moment stock
 * returned, but the shop never saw the list. Most-wanted first, so it reads as
 * what to order next; the addresses are behind each row for the day someone
 * wants to ring round rather than wait for the delivery.
 *
 * They are emailed, or texted if they left a mobile number, automatically when the stock goes back above zero, for
 * whatever reason — a delivery, a cancellation, a return — so there is nothing
 * to press here.
 */
export default function StockRequests({
    rows = [],
    showTold = false,
    totals = {},
}) {
    const tabs = [
        { key: 'waiting', label: 'Waiting', badge: totals.waiting ?? 0 },
        { key: 'all', label: 'Including already told' },
    ];

    const columns = [
        {
            key: 'product',
            header: 'Product',
            render: (r) => (
                <div>
                    <div className="admin-stock-product-name">
                        {r.slug ? (
                            <Link href={ROUTES.PRODUCT_DETAIL(r.slug)}>
                                {r.product}
                            </Link>
                        ) : (
                            r.product
                        )}
                    </div>
                    {r.option && (
                        <div className="admin-field-hint">{r.option}</div>
                    )}
                </div>
            ),
        },
        {
            key: 'stock',
            header: 'Stock now',
            align: 'right',
            render: (r) => (
                <span className={r.stock > 0 ? '' : 'stock-request-empty'}>
                    {r.stock}
                </span>
            ),
        },
        {
            key: 'waiting',
            header: 'Waiting',
            align: 'right',
            render: (r) => <strong>{r.waiting}</strong>,
        },
        {
            key: 'oldest',
            header: 'Waiting since',
            render: (r) =>
                r.oldest_waiting ?? <span className="admin-field-hint">—</span>,
        },
        {
            key: 'who',
            header: 'Who',
            render: (r) => (
                <details className="stock-request-who">
                    <summary>
                        {r.waiting + r.told}{' '}
                        {r.waiting + r.told === 1 ? 'person' : 'people'}
                        {r.told > 0 && ` · ${r.told} told`}
                    </summary>
                    <ul>
                        {r.requests.map((q, i) => (
                            <li key={`${q.contact}-${i}`}>
                                <span>{q.contact}</span>
                                <span className="admin-field-hint">
                                    {q.by === 'text' ? 'by text · ' : ''}
                                    {q.has_account ? 'account · ' : ''}
                                    asked {q.asked}
                                    {q.told ? ` · told ${q.told}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Stock"
            subtitle="Customers waiting for something sold out to come back"
        >
            <Head title="Notify-me requests" />
            <StockTabs current={ROUTES.ADMIN_STOCK_REQUESTS} />

            <Tabs
                variant="enclosed"
                tabs={tabs}
                activeTab={showTold ? 'all' : 'waiting'}
                onChange={(key) =>
                    router.get(
                        ROUTES.ADMIN_STOCK_REQUESTS,
                        key === 'all' ? { told: 1 } : {},
                        { preserveScroll: true, replace: true },
                    )
                }
            />

            <DataTable
                columns={columns}
                data={rows}
                title={`${totals.waiting ?? 0} waiting across ${totals.products ?? 0} ${totals.products === 1 ? 'product' : 'products'}`}
                subtitle="Emailed or texted automatically when the stock goes back above zero — a delivery, a cancellation or a return"
                emptyTitle={showTold ? 'No requests yet' : 'Nobody is waiting'}
                emptyDescription="When a customer presses Notify me on a sold-out product, they appear here."
                emptyIcon={BellRing}
                pagination={false}
            />
        </AdminLayout>
    );
}
