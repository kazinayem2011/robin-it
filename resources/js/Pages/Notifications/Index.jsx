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
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { mainLayout } from '@/Layouts/MainLayout';
import Button from '@/Components/Button';
import Pagination from '@/Components/Pagination';
import SearchInput from '@/Components/SearchInput';
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

/** Turns "order.placed" into "Order placed" for the filter, without a table
 *  of labels that would fall out of date the moment a kind is added. */
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
 * Served to whoever is signed in rather than to admins alone — a customer is
 * told about their own order — so it wears whichever shell that person is
 * used to.
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
       rather than trying to keep two copies of the truth in step. */
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

    const body = (
        <div className="notif-page">
            <Head title={`Notifications — ${siteConfig.name}`} />

            <div className="notif-page-head">
                <div>
                    <h1 className="notif-page-title">Notifications</h1>
                    <p className="notif-page-sub">
                        {unread > 0
                            ? `${unread} unread`
                            : 'Everything here has been read'}
                    </p>
                </div>

                <div className="notif-page-bulk">
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={Check}
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
                </div>
            </div>

            <div className="notif-filters">
                <div className="notif-filter-tabs">
                    {[
                        { value: 'all', label: 'All' },
                        { value: 'unread', label: 'Unread' },
                        { value: 'read', label: 'Read' },
                    ].map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            className={`notif-filter-tab ${
                                (filters.status || 'all') === tab.value
                                    ? 'is-active'
                                    : ''
                            }`}
                            onClick={() => go({ status: tab.value })}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                {/* Only the kinds this person has actually received; a filter
                    offering "Low stock" to a customer is a dead end. */}
                {kinds.length > 1 && (
                    <select
                        className="notif-kind-select"
                        value={filters.kind || ''}
                        onChange={(e) =>
                            go({ kind: e.target.value || undefined })
                        }
                    >
                        <option value="">Everything</option>
                        {kinds.map((kind) => (
                            <option key={kind} value={kind}>
                                {kindLabel(kind)}
                            </option>
                        ))}
                    </select>
                )}

                <SearchInput
                    value={filters.search || ''}
                    onSearch={(term) => go({ search: term || undefined })}
                    placeholder="Search notifications"
                />
            </div>

            {notifications.data.length === 0 ? (
                <div className="notif-page-empty">
                    <Bell size={34} />
                    <p>
                        {filters.search ||
                        filters.kind ||
                        filters.status !== 'all'
                            ? 'Nothing matches that.'
                            : 'Nothing yet. This is where order updates and alerts arrive.'}
                    </p>
                </div>
            ) : (
                <ul className="notif-page-list">
                    {notifications.data.map((item) => {
                        const Icon = ICONS[item.icon] || Bell;

                        return (
                            <li
                                key={item.id}
                                className={`notif-row ${item.read ? '' : 'is-unread'}`}
                            >
                                <span className="notif-row-icon">
                                    <Icon size={16} />
                                </span>

                                <div className="notif-row-text">
                                    <strong>{item.title}</strong>
                                    <span>{item.body}</span>
                                    {/* The exact time as a title on the
                                        relative one: "3 days ago" is what you
                                        read, the date is what you check. */}
                                    <em title={item.on}>{item.at}</em>
                                </div>

                                <div className="notif-row-actions">
                                    {item.url && (
                                        <button
                                            type="button"
                                            className="notif-row-btn"
                                            title="Open what this is about"
                                            onClick={() => open(item)}
                                        >
                                            <ExternalLink size={15} />
                                        </button>
                                    )}

                                    <button
                                        type="button"
                                        className="notif-row-btn"
                                        disabled={busyId === item.id}
                                        title={
                                            item.read
                                                ? 'Mark unread'
                                                : 'Mark read'
                                        }
                                        onClick={() =>
                                            act(
                                                item.id,
                                                () =>
                                                    item.read
                                                        ? notificationService.markUnread(
                                                              item.id,
                                                          )
                                                        : notificationService.markRead(
                                                              item.id,
                                                          ),
                                                'Could not change that.',
                                            )
                                        }
                                    >
                                        {item.read ? (
                                            <Undo2 size={15} />
                                        ) : (
                                            <Check size={15} />
                                        )}
                                    </button>

                                    <button
                                        type="button"
                                        className="notif-row-btn is-danger"
                                        disabled={busyId === item.id}
                                        title="Delete"
                                        onClick={() =>
                                            act(
                                                item.id,
                                                () =>
                                                    notificationService.remove(
                                                        item.id,
                                                    ),
                                                'Could not delete that.',
                                            )
                                        }
                                    >
                                        <Trash2 size={15} />
                                    </button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            {notifications.last_page > 1 && (
                <Pagination
                    links={notifications.links}
                    currentPage={notifications.current_page}
                    totalPages={notifications.last_page}
                    from={notifications.from}
                    to={notifications.to}
                    total={notifications.total}
                    onPageChange={(page) => go({ page })}
                />
            )}
        </div>
    );

    return staff ? (
        <AdminLayout title="Notifications">{body}</AdminLayout>
    ) : (
        body
    );
}

/*
 * Staff already have the admin shell around the body above, so wrapping again
 * would put the storefront header on top of it. A customer gets the site shell
 * they came from.
 */
Notifications.layout = (page) =>
    isStaff(page.props?.auth?.user?.role) ? page : mainLayout(page);

export { Notifications as NotificationsPage };
