import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { MessageSquare, Send } from 'lucide-react';
import AccountLayout from './AccountLayout';
import Button from '@/Components/Button';
import { ROUTES } from '@/constants/endpoints';
import { mainLayout } from '../../Layouts/MainLayout';

/** The date a thread shows: short, and the same shape everywhere on the page. */
const when = (value) =>
    new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

/**
 * A thread as the customer reads it: what they asked, what the shop said, and
 * a box to answer in.
 *
 * The shop's reply is emailed as well, and always was. What was missing is
 * everything around it — no record of what was asked, no way to tell whether
 * it had been answered, and nowhere to write back that the shop would see,
 * because an email reply lands in a mailbox rather than in the inbox screen.
 */
function Thread({ thread, open, onToggle }) {
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [problem, setProblem] = useState(null);

    const send = (event) => {
        event.preventDefault();

        if (!body.trim() || sending) return;

        setSending(true);
        setProblem(null);

        router.post(
            ROUTES.ACCOUNT_MESSAGE_REPLY(thread.id),
            { body },
            {
                preserveScroll: true,
                onSuccess: () => setBody(''),
                onError: (errors) =>
                    setProblem(errors?.body || 'That did not send. Try again.'),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <article className="dash-msg-thread">
            <button
                type="button"
                className="dash-msg-head"
                onClick={onToggle}
                aria-expanded={open}
            >
                <span className="dash-msg-head-text">
                    <strong>{thread.subject}</strong>
                    <span className="dash-msg-when">
                        Sent {when(thread.created_at)} ·{' '}
                        {thread.replies.length === 0
                            ? 'No reply yet'
                            : `${thread.replies.length} repl${thread.replies.length === 1 ? 'y' : 'ies'}`}
                    </span>
                </span>
                {/* The inbox's own states — new, in progress, closed — which
                    are not an order's, and not what StatusBadge draws. */}
                <span
                    className={`dash-msg-state dash-msg-state-${thread.status}`}
                >
                    {thread.status_label}
                </span>
            </button>

            {open && (
                <div className="dash-msg-body">
                    <div className="dash-msg-note dash-msg-note-mine">
                        <span className="dash-msg-author">
                            You · {when(thread.created_at)}
                        </span>
                        <p>{thread.message}</p>
                    </div>

                    {thread.replies.map((reply) => (
                        <div
                            key={reply.id}
                            className={`dash-msg-note ${reply.from_customer ? 'dash-msg-note-mine' : 'dash-msg-note-shop'}`}
                        >
                            <span className="dash-msg-author">
                                {reply.from_customer
                                    ? 'You'
                                    : reply.author_name}{' '}
                                · {when(reply.created_at)}
                            </span>
                            <p>{reply.body}</p>
                        </div>
                    ))}

                    <form className="dash-msg-reply" onSubmit={send} noValidate>
                        <label
                            className="auth-label"
                            htmlFor={`reply-${thread.id}`}
                        >
                            Write back
                        </label>
                        <textarea
                            id={`reply-${thread.id}`}
                            className="auth-text-input dash-textarea-custom"
                            value={body}
                            onChange={(event) => setBody(event.target.value)}
                            placeholder="Anything else we can help with?"
                            maxLength={4000}
                        />
                        {problem && (
                            <span className="auth-field-error">{problem}</span>
                        )}
                        <Button
                            type="submit"
                            variant="primary"
                            size="sm"
                            icon={Send}
                            loading={sending}
                            disabled={!body.trim()}
                        >
                            Send
                        </Button>
                    </form>
                </div>
            )}
        </article>
    );
}

/**
 * @param threads Their own enquiries, newest first. A message sent while
 *                signed out belongs to nobody, so it is answered by email and
 *                does not appear here.
 */
export default function Messages({
    user,
    navCounts,
    techPoints,
    threads = [],
}) {
    /*
     * The one the bell led them to, else the newest: a thread the shop has
     * just answered is the reason anybody opens this page.
     */
    const asked =
        typeof window !== 'undefined'
            ? Number(new URLSearchParams(window.location.search).get('message'))
            : 0;

    const [openId, setOpenId] = useState(
        threads.some((thread) => thread.id === asked)
            ? asked
            : (threads[0]?.id ?? null),
    );

    return (
        <AccountLayout
            title="Messages"
            active="messages"
            user={user}
            navCounts={navCounts}
            techPoints={techPoints}
        >
            <div>
                <div className="dash-tab-header">
                    <div>
                        <h2>Messages</h2>
                        <p>
                            What you have asked us, and what we said back. We
                            reply here and by email.
                        </p>
                    </div>
                </div>

                {threads.length === 0 ? (
                    <div className="dash-empty-box">
                        <MessageSquare size={44} className="dash-empty-icon" />
                        <h4 className="dash-empty-text">No messages yet</h4>
                        <p className="dash-empty-text">
                            Ask us anything — about an order, a part, or a
                            warranty. Replies appear here.
                        </p>
                        <Link
                            href={ROUTES.CONTACT}
                            className="btn btn-primary btn-sm mt-3"
                        >
                            Write to us
                        </Link>
                    </div>
                ) : (
                    <div className="dash-msg-list">
                        {threads.map((thread) => (
                            <Thread
                                key={thread.id}
                                thread={thread}
                                open={openId === thread.id}
                                onToggle={() =>
                                    setOpenId(
                                        openId === thread.id ? null : thread.id,
                                    )
                                }
                            />
                        ))}
                    </div>
                )}
            </div>
        </AccountLayout>
    );
}

// Persistent shell: mounts once, survives navigation.
Messages.layout = mainLayout;
