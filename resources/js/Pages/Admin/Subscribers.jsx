import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { MailX, Download, Users } from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Tabs from '@/Components/Tabs';
import Pagination from '@/Components/Pagination';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { ROUTES } from '@/constants/endpoints';

const TABS = [
    { id: 'subscribed', label: 'Subscribed' },
    { id: 'unsubscribed', label: 'Unsubscribed' },
];

/**
 * Who has asked to hear from the shop.
 */
export default function AdminSubscribers({
    subscribers = { data: [] },
    filters = {},
    counts = {},
}) {
    const showing =
        filters.status === 'unsubscribed' ? 'unsubscribed' : 'subscribed';

    const filterBy = (status) =>
        router.get(
            ROUTES.ADMIN_SUBSCRIBERS,
            status === 'unsubscribed' ? { status } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const remove = async (row) => {
        if (
            !confirm(
                `Stop emailing ${row.email}? The row is kept, marked unsubscribed — a deleted one would be added back by the next import.`,
            )
        ) {
            return;
        }

        try {
            const data = await adminService.removeSubscriber(row.id);
            toast.success(data?.message || 'Removed from the list.');
            router.reload({ only: ['subscribers', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not do that.');
        }
    };

    const columns = [
        {
            key: 'email',
            header: 'Email',
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
            render: (r) =>
                r.source ?? <span className="admin-field-hint">—</span>,
        },
        {
            key: 'when',
            header: 'When',
            render: (r) =>
                (showing === 'unsubscribed'
                    ? r.unsubscribed_at
                    : r.subscribed_at
                )?.slice(0, 10) ?? <span className="admin-field-hint">—</span>,
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (r) =>
                showing === 'subscribed' && (
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Stop emailing this address"
                        onClick={() => remove(r)}
                    >
                        <MailX size={14} />
                    </button>
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
                tabs={TABS.map((t) => ({
                    ...t,
                    label: counts[t.id]
                        ? `${t.label} (${counts[t.id]})`
                        : t.label,
                }))}
                activeTab={showing}
                onChange={filterBy}
            />

            <DataTable
                columns={columns}
                data={subscribers.data ?? []}
                title="Mailing list"
                subtitle={
                    showing === 'subscribed'
                        ? 'Everyone currently on the list'
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
