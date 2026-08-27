import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Inbox,
    Mail,
    Phone,
    CornerDownRight,
    Check,
    RotateCcw,
    Send,
    AlertTriangle,
} from 'lucide-react';
import Button from '@/Components/Button';
import Tabs from '@/Components/Tabs';
import Pagination from '@/Components/Pagination';
import EmptyState from '@/Components/EmptyState';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { ROUTES } from '@/constants/endpoints';
import './Messages.css';

const TABS = [
    { id: '', label: 'All' },
    { id: 'new', label: 'New' },
    { id: 'open', label: 'In progress' },
    { id: 'closed', label: 'Closed' },
];

/**
 * The contact inbox: what customers wrote in, and what was said back.
 */
export default function AdminMessages({
    messages = { data: [] },
    filters = {},
    counts = {},
}) {
    const rows = messages.data ?? [];

    const [openId, setOpenId] = useState(null);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);

    const show = (id) => {
        setOpenId(openId === id ? null : id);
        setDraft('');
    };

    const filterBy = (status) =>
        router.get(ROUTES.ADMIN_MESSAGES, status ? { status } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const send = async (message, andClose) => {
        if (!draft.trim()) {
            toast.error('Write something before sending.');
            return;
        }

        setSending(true);
        try {
            const data = await adminService.replyToMessage(
                message.id,
                draft.trim(),
                andClose,
            );
            setDraft('');
            // Said plainly: the answer is saved either way, but whoever sent it
            // needs to know when the customer did not actually get an email.
            if (data?.data?.emailed === false) {
                toast.error(data.message, 'Saved, but not emailed');
            } else {
                toast.success(data?.message || 'Replied.');
            }
            router.reload({ only: ['messages', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not send that reply.');
        } finally {
            setSending(false);
        }
    };

    const setStatus = async (message, status) => {
        try {
            const data = await adminService.setMessageStatus(
                message.id,
                status,
            );
            toast.success(data?.message || 'Updated.');
            router.reload({ only: ['messages', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not update that.');
        }
    };

    return (
        <AdminLayout
            title="Messages"
            subtitle="What customers wrote in, and what was said back"
        >
            <Head title="Messages" />

            <Tabs
                variant="enclosed"
                tabs={TABS.map((t) => ({
                    ...t,
                    label:
                        t.id && counts[t.id]
                            ? `${t.label} (${counts[t.id]})`
                            : t.label,
                }))}
                activeTab={filters.status || ''}
                onChange={filterBy}
            />

            {rows.length === 0 ? (
                <EmptyState
                    icon={Inbox}
                    title="Nothing here"
                    description={
                        filters.status
                            ? 'No messages with that status.'
                            : 'When somebody writes in from the Contact page, it lands here.'
                    }
                />
            ) : (
                <div className="msg-list">
                    {rows.map((m) => (
                        <article
                            key={m.id}
                            className={`msg-row ${openId === m.id ? 'is-open' : ''} ${m.status === 'new' ? 'is-new' : ''}`}
                        >
                            <button
                                type="button"
                                className="msg-summary"
                                onClick={() => show(m.id)}
                            >
                                <span className="msg-summary-main">
                                    <span className="msg-subject">
                                        {m.subject}
                                    </span>
                                    <span className="msg-from">
                                        {m.name} · {m.email}
                                    </span>
                                </span>
                                <span className="msg-summary-meta">
                                    {m.replies?.length > 0 && (
                                        <span className="msg-reply-count">
                                            <CornerDownRight size={12} />
                                            {m.replies.length}
                                        </span>
                                    )}
                                    <span
                                        className={`msg-status msg-status-${m.status}`}
                                    >
                                        {m.status_label}
                                    </span>
                                </span>
                            </button>

                            {openId === m.id && (
                                <div className="msg-body">
                                    <div className="msg-contact-lines">
                                        <span>
                                            <Mail size={13} /> {m.email}
                                        </span>
                                        {m.phone && (
                                            <span>
                                                <Phone size={13} /> {m.phone}
                                            </span>
                                        )}
                                    </div>

                                    <p className="msg-text">{m.message}</p>

                                    {m.replies?.map((r) => (
                                        <div key={r.id} className="msg-reply">
                                            <div className="msg-reply-head">
                                                <strong>{r.author_name}</strong>
                                                {!r.emailed && (
                                                    <span className="msg-not-emailed">
                                                        <AlertTriangle
                                                            size={12}
                                                        />{' '}
                                                        not emailed
                                                    </span>
                                                )}
                                            </div>
                                            <p>{r.body}</p>
                                        </div>
                                    ))}

                                    <textarea
                                        className="msg-draft"
                                        rows="4"
                                        value={draft}
                                        onChange={(e) =>
                                            setDraft(e.target.value)
                                        }
                                        placeholder={`Reply to ${m.name}. This is emailed to ${m.email}.`}
                                    />

                                    <div className="msg-actions">
                                        <Button
                                            size="sm"
                                            icon={Send}
                                            disabled={sending}
                                            onClick={() => send(m, false)}
                                        >
                                            {sending
                                                ? 'Sending…'
                                                : 'Send reply'}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            icon={Check}
                                            disabled={sending}
                                            onClick={() => send(m, true)}
                                        >
                                            Reply &amp; close
                                        </Button>

                                        <span className="msg-actions-spacer" />

                                        {m.is_closed ? (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                icon={RotateCcw}
                                                onClick={() =>
                                                    setStatus(m, 'open')
                                                }
                                            >
                                                Reopen
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                icon={Check}
                                                onClick={() =>
                                                    setStatus(m, 'closed')
                                                }
                                            >
                                                Close without replying
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}

            {messages.last_page > 1 && (
                <Pagination
                    links={messages.links}
                    currentPage={messages.current_page}
                    totalPages={messages.last_page}
                    from={messages.from}
                    to={messages.to}
                    total={messages.total}
                />
            )}
        </AdminLayout>
    );
}
