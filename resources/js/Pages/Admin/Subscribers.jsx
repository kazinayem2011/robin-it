import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Download, Users } from 'lucide-react';
import Button from '@/Components/Button';
import { Checkbox } from '@/Components/Checkbox';
import DataTable from '@/Components/DataTable';
import Tabs from '@/Components/Tabs';
import Pagination from '@/Components/Pagination';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { ROUTES } from '@/constants/endpoints';

// `key`, not `id` — Tabs reads tab.key, and an `id` made every tab inert.
const TABS = [
    { key: 'subscribed', label: 'Receiving email' },
    { key: 'unsubscribed', label: 'Not receiving' },
];

/**
 * Who has asked to hear from the shop.
 *
 * Nobody is deleted here — a deleted address is added back by the next import
 * as though they had never asked to be left alone. The switch moves; the row
 * stays.
 */
export default function AdminSubscribers({
    subscribers = { data: [] },
    filters = {},
    counts = {},
}) {
    const showing =
        filters.status === 'unsubscribed' ? 'unsubscribed' : 'subscribed';
    const sort = filters.sort ?? { by: 'subscribed_at', dir: 'desc' };

    const go = (params) =>
        router.get(
            ROUTES.ADMIN_SUBSCRIBERS,
            { status: showing, q: filters.q || undefined, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // Clicking the column you are already on turns the order around.
    const onSort = (by) =>
        go({
            sort: by,
            dir: sort.by === by && sort.dir === 'desc' ? 'asc' : 'desc',
        });

    const setActive = async (row, active) => {
        try {
            const data = await adminService.setSubscriberActive(row.id, active);
            toast.success(data?.message || 'Updated.');
            router.reload({ only: ['subscribers', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not change that.');
        }
    };

    const columns = [
        {
            key: 'email',
            header: 'Email',
            sortable: true,
            render: (r) => (
                <div>
                    <div className="admin-stock-product-name">{r.email}</div>
                    {r.name && <div className="admin-field-hint">{r.name}</div>}
                </div>
            ),
        },
        {
            key: 'source',
            header: 'Signed up via',
            sortable: true,
            render: (r) =>
                r.source ?? <span className="admin-field-hint">—</span>,
        },
        {
            key:
                showing === 'unsubscribed'
                    ? 'unsubscribed_at'
                    : 'subscribed_at',
            header: showing === 'unsubscribed' ? 'Stopped' : 'Joined',
            sortable: true,
            render: (r) =>
                (showing === 'unsubscribed'
                    ? r.unsubscribed_at
                    : r.subscribed_at
                )?.slice(0, 10) ?? <span className="admin-field-hint">—</span>,
        },
        {
            key: 'active',
            header: 'Receiving email',
            align: 'right',
            render: (r) => (
                <Checkbox
                    name={`sub-${r.id}`}
                    checked={r.status === 'subscribed'}
                    onChange={(e) => setActive(r, e.target.checked)}
                    label={r.status === 'subscribed' ? 'Yes' : 'No'}
                />
            ),
        },
    ];

    return (
        <AdminLayout
            title="Subscribers"
            subtitle="Who has asked to hear from the shop"
        >
            <Head title="Subscribers" />

            <Tabs
                variant="enclosed"
                tabs={TABS.map((t) => ({ ...t, badge: counts[t.key] ?? 0 }))}
                activeTab={showing}
                onChange={(status) =>
                    go({ status, sort: undefined, dir: undefined })
                }
            />

            <DataTable
                columns={columns}
                data={subscribers.data ?? []}
                sort={sort}
                onSort={onSort}
                searchable
                searchValue={filters.q || ''}
                onSearch={(q) => go({ q: q || undefined })}
                searchPlaceholder="Search by email or name…"
                title="Mailing list"
                subtitle={
                    showing === 'subscribed'
                        ? 'Everyone currently receiving email'
                        : 'People who have asked to be left alone'
                }
                headerActions={
                    showing === 'subscribed' && (
                        // A plain link, not fetch: the browser saves the file.
                        <a href="/admin/subscribers/export" download>
                            <Button variant="secondary" icon={Download}>
                                Export CSV
                            </Button>
                        </a>
                    )
                }
                emptyTitle="Nobody yet"
                emptyDescription="Addresses from the footer's newsletter box land here."
                emptyIcon={Users}
                pagination={false}
            />

            {subscribers.last_page > 1 && (
                <Pagination
                    links={subscribers.links}
                    currentPage={subscribers.current_page}
                    totalPages={subscribers.last_page}
                    from={subscribers.from}
                    to={subscribers.to}
                    total={subscribers.total}
                />
            )}
        </AdminLayout>
    );
}
