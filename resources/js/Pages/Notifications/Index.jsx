import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Bell,
    ShoppingCart,
    HelpCircle,
    MessageSquare,
    PackageMinus,
    Check,
    Undo2,
    Trash2,
    ExternalLink,
    CheckCheck,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { mainLayout } from '@/Layouts/MainLayout';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Select from '@/Components/Select';
import Tabs from '@/Components/Tabs';
import { toast } from '@/Components/Toast';
import { notificationService } from '@/services';
import siteConfig from '@/constants/siteConfig';
import { ROUTES } from '@/constants/endpoints';
import './Notifications.css';

/* The same map the bell uses. A kind that arrives without one falls back to a
   bell rather than rendering nothing. */
const ICONS = {
    order: ShoppingCart,
    question: HelpCircle,
    message: MessageSquare,
    stock: PackageMinus,
};

/** Turns "order.placed" into "Order placed", so a kind added later needs no
 *  entry in a table of labels that would fall out of date. */
const kindLabel = (kind) => {
    const words = String(kind)
        .replace(/[._-]+/g, ' ')
        .trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
};

const isStaff = (role) => Boolean(role) && role !== 'customer';

/**
 * Every notification, which the bell is not.
 *
 * The bell holds the last twenty and offers one action: open it, which marks
 * it read on the way past. Anything older than a busy afternoon was gone from
 * view while still sitting in the table, and there was no way to mark one back
 * to unread, throw one away, or find the one about a particular order.
 *
 * Built from Tabs and DataTable like every other list in the admin, rather
 * than from its own markup: the search box, the paging, the empty state and
 * the header actions all come with them, and it then looks like the rest of
 * the screens instead of like a page somebody added later.
 *
 * Served to whoever is signed in rather than to admins alone — a customer is
 * told about their own order — so it wears whichever shell that person knows.
 */
export default function Notifications({
    notifications = { data: [] },
    filters = {},
    kinds = [],
    unread = 0,
}) {
    const { auth } = usePage().props;
    const staff = isStaff(auth?.user?.role);

    const [busyId, setBusyId] = useState(null);
    const [working, setWorking] = useState(false);

    const go = (params) =>
        router.get(
            ROUTES.NOTIFICATIONS,
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    /* The list is server-rendered, so anything that changes a row reloads it
       rather than keeping a second copy of the truth in step. */
    const refresh = () => router.reload({ preserveScroll: true });

    const act = async (id, fn, failure) => {
        setBusyId(id);
        try {
            await fn();
            refresh();
        } catch (error) {
            toast.error(error?.message || failure);
        } finally {
            setBusyId(null);
        }
    };

    const bulk = async (fn, failure) => {
        setWorking(true);
        try {
            const res = await fn();
            toast.success(res?.message || 'Done.');
            refresh();
        } catch (error) {
            toast.error(error?.message || failure);
        } finally {
            setWorking(false);
        }
    };

    /* Opening one marks it read, the same as the bell — reading it is what
       makes it read, and asking separately would be a chore nobody wants. */
    const open = async (item) => {
        if (!item.read) {
            try {
                await notificationService.markRead(item.id);
            } catch {
                /* Following the link matters more than the flag. */
            }
        }

        if (item.url) router.visit(item.url);
    };

    const columns = [
        {
            key: 'notification',
            header: 'Notification',
            render: (item) => {
                const Icon = ICONS[item.icon] || Bell;

                return (
                    <div
                        className={`notif-cell ${item.read ? '' : 'is-unread'}`}
                    >
                        <span className="notif-cell-icon">
                            <Icon size={15} />
                        </span>
                        <div className="notif-cell-text">
                            <strong>{item.title}</strong>
                            <span>{item.body}</span>
                        </div>
                    </div>
                );
            },
        },
        {
            key: 'when',
            header: 'When',
            /* The exact time as a title on the relative one: "3 days ago" is
               what you read, the date is what you check. */
            render: (item) => (
                <span className="notif-when" title={item.on}>
                    {item.at}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (item) => (
                <div className="admin-input-row-flex">
                    {item.url && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Open what this is about"
                            onClick={() => open(item)}
                        >
                            <ExternalLink size={14} />
                        </button>
                    )}

                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        disabled={busyId === item.id}
                        title={item.read ? 'Mark unread' : 'Mark read'}
                        onClick={() =>
                            act(
                                item.id,
                                () =>
                                    item.read
                                        ? notificationService.markUnread(
                                              item.id,
                                          )
                                        : notificationService.markRead(item.id),
                                'Could not change that.',
                            )
                        }
                    >
                        {item.read ? <Undo2 size={14} /> : <Check size={14} />}
                    </button>

                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        disabled={busyId === item.id}
                        title="Delete"
                        onClick={() =>
                            act(
                                item.id,
                                () => notificationService.remove(item.id),
                                'Could not delete that.',
                            )
                        }
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            ),
        },
    ];

    const tabs = [
        { key: 'all', label: 'All' },
        { key: 'unread', label: 'Unread', badge: unread || undefined },
        { key: 'read', label: 'Read' },
    ];

    const body = (
        <>
            <Head title={`Notifications — ${siteConfig.name}`} />

            <Tabs
                variant="enclosed"
                tabs={tabs}
                activeTab={filters.status || 'all'}
                onChange={(status) => go({ status })}
            />

            <DataTable
                columns={columns}
                data={notifications.data ?? []}
                title="Notifications"
                subtitle={
                    unread > 0
                        ? `${unread} unread`
                        : 'Everything here has been read'
                }
                searchable
                searchValue={filters.search || ''}
                onSearch={(term) => go({ search: term || undefined })}
                searchPlaceholder="Search notifications"
                headerActions={
                    <>
                        {/* Only the kinds this person has actually received.
                            Offering "Low stock" to a customer is a dead end
                            dressed as a choice. */}
                        {kinds.length > 1 && (
                            <Select
                                className="admin-filter-picker"
                                aria-label="Filter by kind"
                                value={filters.kind || ''}
                                onChange={(e) =>
                                    go({ kind: e.target.value || undefined })
                                }
                                options={[
                                    { value: '', label: 'Everything' },
                                    ...kinds.map((kind) => ({
                                        value: kind,
                                        label: kindLabel(kind),
                                    })),
                                ]}
                            />
                        )}

                        <Button
                            variant="secondary"
                            size="sm"
                            icon={CheckCheck}
                            disabled={working || unread === 0}
                            onClick={() =>
                                bulk(
                                    notificationService.markAllRead,
                                    'Could not mark them read.',
                                )
                            }
                        >
                            Mark all read
                        </Button>

                        {/* Only the ones already read. Clearing the unread ones
                            would throw away what nobody has looked at, which is
                            the opposite of tidying up. */}
                        <Button
                            variant="secondary"
                            size="sm"
                            icon={Trash2}
                            disabled={working}
                            onClick={() => {
                                if (
                                    window.confirm(
                                        'Delete every notification you have already read? The unread ones stay.',
                                    )
                                ) {
                                    bulk(
                                        notificationService.clearRead,
                                        'Could not clear them.',
                                    );
                                }
                            }}
                        >
                            Clear read
                        </Button>
                    </>
                }
                emptyTitle="Nothing here"
                emptyDescription="Order updates and alerts arrive here as they happen."
                emptyIcon={Bell}
                pagination
                paginationLinks={notifications.links}
                paginationMeta={notifications}
                onPageChange={(page) => go({ page })}
            />
        </>
    );

    /*
     * Staff get the admin shell, which already supplies the page's padding —
     * this used to add its own on top, which is why it sat further from the
     * edge than every other screen. A customer gets the site's container.
     */
    return staff ? (
        <AdminLayout
            title="Notifications"
            subtitle="Everything the shop has told you about"
        >
            {body}
        </AdminLayout>
    ) : (
        <div className="notif-page-wrapper container">{body}</div>
    );
}

Notifications.layout = (page) =>
    isStaff(page.props?.auth?.user?.role) ? page : mainLayout(page);
