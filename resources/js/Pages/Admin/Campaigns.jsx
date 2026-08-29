import React, { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Megaphone,
    Plus,
    Send,
    Trash2,
    Pencil,
    Eye,
    ListChecks,
} from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Pagination from '@/Components/Pagination';
import Tabs from '@/Components/Tabs';
import Modal from '@/Components/Modal';
import CampaignComposer from './Components/CampaignComposer';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import './Campaigns.css';

/**
 * Telling every customer about something at once.
 *
 * The shop had been collecting a mailing list for a year with nowhere to send
 * it, and could only text a customer about their own order.
 *
 * The screen is built around the two questions worth asking before a blast:
 * who is this actually going to, and what will the texts cost. Both are
 * answered before the send button does anything.
 */
export default function Campaigns({
    campaigns = { data: [] },
    filters = {},
    statuses = {},
    channels = {},
    audiences = {},
}) {
    const [writing, setWriting] = useState(false);
    const [editing, setEditing] = useState(null);
    const [history, setHistory] = useState(null);

    const go = (params) =>
        router.get(
            '/admin/campaigns',
            { ...filters, ...params },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    const refresh = () => router.reload({ only: ['campaigns'] });

    const send = async (campaign) => {
        if (
            !window.confirm(
                `Send "${campaign.title}" now? It goes to everyone in that audience and cannot be recalled.`,
            )
        ) {
            return;
        }

        try {
            const res = await adminService.sendCampaign(campaign.id);
            toast.success(res?.message || 'On its way.');
            refresh();
        } catch (err) {
            toast.error(err?.message || 'Could not send that campaign.');
        }
    };

    const remove = async (campaign) => {
        if (!window.confirm(`Delete "${campaign.title}"?`)) return;

        try {
            await adminService.deleteCampaign(campaign.id);
            toast.success('Campaign deleted.');
            refresh();
        } catch (err) {
            toast.error(err?.message || 'Could not delete that.');
        }
    };

    const columns = [
        {
            key: 'title',
            header: 'Campaign',
            render: (c) => (
                <div>
                    <strong className="admin-table-item-title">
                        {c.title}
                    </strong>
                    <div className="admin-field-hint">
                        {c.channel_label} · {c.audience_label}
                    </div>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Where it is',
            render: (c) => (
                <div>
                    <span className={`cmp-badge cmp-${c.status}`}>
                        {c.status_label}
                    </span>
                    {c.status === 'sending' && (
                        <div className="admin-field-hint">
                            {c.sent_count} of {c.recipient_count} done
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'reach',
            header: 'Reached',
            render: (c) =>
                c.recipient_count > 0 ? (
                    <div>
                        <strong>{c.sent_count}</strong>
                        <span className="admin-field-hint">
                            {' '}
                            / {c.recipient_count}
                        </span>
                        {c.failed_count > 0 && (
                            <div className="cmp-failed">
                                {c.failed_count} failed
                            </div>
                        )}
                    </div>
                ) : (
                    <span className="admin-field-hint">—</span>
                ),
        },
        {
            key: 'parts',
            header: 'SMS parts',
            render: (c) =>
                c.sms_parts > 0 ? (
                    c.sms_parts
                ) : (
                    <span className="admin-field-hint">—</span>
                ),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (c) => (
                <div className="admin-input-row-flex">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title={
                            c.status === 'draft'
                                ? 'Edit and preview'
                                : 'See what was sent'
                        }
                        onClick={() => setEditing(c)}
                    >
                        {c.status === 'draft' ? (
                            <Pencil size={14} />
                        ) : (
                            <Eye size={14} />
                        )}
                    </button>

                    {/* Who got it, who did not, and why not. */}
                    {c.recipient_count > 0 && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Delivery history"
                            onClick={() => setHistory(c)}
                        >
                            <ListChecks size={14} />
                        </button>
                    )}

                    {c.status === 'draft' && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Send it now"
                            onClick={() => send(c)}
                        >
                            <Send size={14} />
                        </button>
                    )}
                    {c.status !== 'sending' && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Delete"
                            onClick={() => remove(c)}
                        >
                            <Trash2 size={14} />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Campaigns"
            subtitle="One message to the whole list, by email or text"
        >
            <Head title="Campaigns" />

            <Tabs
                variant="enclosed"
                tabs={[
                    { key: '', label: 'All' },
                    ...Object.entries(statuses).map(([key, label]) => ({
                        key,
                        label,
                    })),
                ]}
                activeTab={filters.status || ''}
                onChange={(status) => go({ status: status || undefined })}
            />

            <DataTable
                columns={columns}
                data={campaigns.data ?? []}
                title="Campaigns"
                subtitle="A draft can be rewritten; once it has gone out it cannot be recalled or corrected"
                headerActions={
                    <Button
                        variant="primary"
                        size="sm"
                        icon={Plus}
                        onClick={() => setWriting(true)}
                    >
                        Write one
                    </Button>
                }
                emptyTitle="No campaigns yet"
                emptyDescription="Write one to tell your mailing list or your customers about a sale, a new arrival, or a change of hours."
                emptyIcon={Megaphone}
                pagination={false}
            />

            {campaigns.last_page > 1 && (
                <Pagination
                    links={campaigns.links}
                    currentPage={campaigns.current_page}
                    totalPages={campaigns.last_page}
                    from={campaigns.from}
                    to={campaigns.to}
                    total={campaigns.total}
                />
            )}

            <CampaignComposer
                open={writing || Boolean(editing)}
                campaign={editing}
                channels={channels}
                audiences={audiences}
                onClose={() => {
                    setWriting(false);
                    setEditing(null);
                }}
                onSaved={() => {
                    setWriting(false);
                    setEditing(null);
                    refresh();
                }}
            />

            <DeliveryHistory
                campaign={history}
                onClose={() => setHistory(null)}
            />
        </AdminLayout>
    );
}

/**
 * Who got it, who did not, and why not.
 *
 * The reason worth keeping: "sent to 4,812 of 4,900" is only useful if
 * somebody can find out what happened to the other 88.
 */
function DeliveryHistory({ campaign, onClose }) {
    const [rows, setRows] = useState([]);
    const [filter, setFilter] = useState('');
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!campaign) return;

        setLoading(true);
        adminService
            .getCampaignRecipients(campaign.id, { status: filter || undefined })
            /*
             * The service already hands back the payload — the axios
             * interceptor unwraps the envelope once and the service unwraps it
             * again. Reaching for .data here looked for it on the array and
             * quietly found nothing, so the table rendered empty while the
             * request returned 200.
             */
            .then((res) =>
                setRows(Array.isArray(res) ? res : (res?.data ?? [])),
            )
            .catch(() => setRows([]))
            .finally(() => setLoading(false));
    }, [campaign, filter]);

    return (
        <Modal
            isOpen={Boolean(campaign)}
            onClose={onClose}
            title={`${campaign?.title ?? ''} — who got it`}
            maxWidth="640px"
            footer={
                <Button variant="secondary" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <div className="cmp-history-filters">
                {[
                    ['', 'Everyone'],
                    ['sent', 'Delivered'],
                    ['failed', 'Failed'],
                    ['pending', 'Still queued'],
                ].map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        className={filter === key ? 'is-on' : ''}
                        onClick={() => setFilter(key)}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {loading ? (
                <p className="admin-field-hint">Loading…</p>
            ) : rows.length === 0 ? (
                <p className="admin-field-hint">Nothing here.</p>
            ) : (
                <table className="cmp-history">
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td>
                                    <strong>{r.contact}</strong>
                                    {r.name && <small> · {r.name}</small>}
                                </td>
                                <td>{r.channel}</td>
                                <td>
                                    <span
                                        className={`cmp-badge cmp-${r.status === 'sent' ? 'sent' : r.status}`}
                                    >
                                        {r.status}
                                    </span>
                                    {r.error && (
                                        <div className="cmp-failed">
                                            {r.error}
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {/*
             * The endpoint pages at fifty. On a campaign to several thousand
             * that is a small window, and a list that stops without saying so
             * reads as the whole story.
             */}
            {rows.length >= 50 && (
                <p className="admin-field-hint">
                    Showing the most recent 50 of {campaign?.recipient_count}.
                    Filter by outcome to narrow it down.
                </p>
            )}
        </Modal>
    );
}
