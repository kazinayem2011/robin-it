import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Hash, ShieldCheck, ShieldOff } from 'lucide-react';
import DataTable from '@/Components/DataTable';
import Pagination from '@/Components/Pagination';
import Tabs from '@/Components/Tabs';
import { ROUTES } from '@/constants/endpoints';
import './Count.css';

/**
 * Every unit the shop tracks by serial, and where it is.
 *
 * The question this answers is the one asked at the counter when somebody
 * walks in with a dead card: is this ours, who bought it, and is it covered.
 */
export default function StockSerials({
    serials = { data: [] },
    filters = {},
    statuses = {},
    branch = null,
    counts = {},
}) {
    const go = (params) =>
        router.get(
            ROUTES.ADMIN_STOCK_SERIALS,
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const tabs = [
        { key: '', label: 'All' },
        ...Object.entries(statuses).map(([key, label]) => ({
            key,
            label,
            badge: counts[key] ?? 0,
        })),
    ];

    const columns = [
        {
            key: 'serial',
            header: 'Serial',
            render: (s) => <code className="serial-code">{s.serial}</code>,
        },
        {
            key: 'product',
            header: 'Product',
            render: (s) => (
                <div className="admin-stock-product-name">{s.product}</div>
            ),
        },
        {
            key: 'status',
            header: 'Where it is',
            render: (s) => (
                <div>
                    <div>{s.status_label}</div>
                    <div className="admin-field-hint">
                        {s.order_number
                            ? `Order ${s.order_number}`
                            : (s.store ?? '—')}
                    </div>
                </div>
            ),
        },
        {
            key: 'sold_at',
            header: 'Sold',
            render: (s) =>
                s.sold_at ?? <span className="admin-field-hint">—</span>,
        },
        {
            key: 'warranty',
            header: 'Warranty',
            render: (s) => {
                // Null, not false, for a unit still on the shelf: it has no
                // cover to be inside or outside of yet.
                if (s.under_warranty === null) {
                    return <span className="admin-field-hint">—</span>;
                }

                return s.under_warranty ? (
                    <span className="serial-covered">
                        <ShieldCheck size={13} /> until {s.warranty_until}
                    </span>
                ) : (
                    <span className="serial-expired">
                        <ShieldOff size={13} /> ended {s.warranty_until}
                    </span>
                );
            },
        },
    ];

    return (
        <AdminLayout
            title="Serial numbers"
            subtitle={
                branch
                    ? `Units tracked at ${branch}`
                    : 'Which unit went to which customer'
            }
        >
            <Head title="Serial numbers" />

            <Tabs
                variant="enclosed"
                tabs={tabs}
                activeTab={filters.status || ''}
                onChange={(status) => go({ status: status || undefined })}
            />

            <DataTable
                columns={columns}
                data={serials.data ?? []}
                searchable
                searchValue={filters.q || ''}
                onSearch={(q) => go({ q: q || undefined })}
                searchPlaceholder="Serial, product or order number…"
                title="Tracked units"
                subtitle="Recorded when a delivery is received, and tied to an order when it ships"
                emptyTitle="No serials recorded"
                emptyDescription="Serial numbers are entered against a line when you receive a delivery. Anything you do not track by unit can be left blank."
                emptyIcon={Hash}
                pagination={false}
            />

            {serials.last_page > 1 && (
                <Pagination
                    links={serials.links}
                    currentPage={serials.current_page}
                    totalPages={serials.last_page}
                    from={serials.from}
                    to={serials.to}
                    total={serials.total}
                />
            )}
        </AdminLayout>
    );
}
