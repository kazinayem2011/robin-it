import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Pagination from '../../Components/Pagination';
import FormInput from '../../Components/FormInput';
import { toast } from '../../Components/Toast';
import { API_ENDPOINTS } from '../../constants/endpoints';
import axiosInstance from '../../services/axiosInstance';
import './ProductQuestions.css';
import {
    MessageSquare,
    Send,
    Eye,
    EyeOff,
    Trash2,
    ExternalLink,
    UserRound,
    CheckCircle2,
} from 'lucide-react';

/*
 * Unanswered first, because an unanswered question is a shopper still deciding
 * and the oldest one has been waiting longest. "All" exists to find something
 * again, not as the place to work from.
 */
const TABS = [
    { key: 'unanswered', label: 'Needs an answer' },
    { key: 'unpublished', label: 'Not published' },
    { key: 'all', label: 'All' },
];

export default function AdminProductQuestions({
    questions = { data: [] },
    filters = {},
    counts = {},
}) {
    const [busyId, setBusyId] = useState(null);
    // Keyed by question id: several can be part-typed at once, and a single
    // shared draft would put one person's answer under another's question.
    const [drafts, setDrafts] = useState({});

    const rows = questions.data ?? [];

    const draftFor = (q) => drafts[q.id] ?? q.answer ?? '';

    const setDraft = (id, value) =>
        setDrafts((prev) => ({ ...prev, [id]: value }));

    const submitAnswer = async (question) => {
        const answer = draftFor(question).trim();

        if (answer.length < 2) {
            toast.error('Write an answer first.');
            return;
        }

        setBusyId(question.id);

        try {
            await axiosInstance.patch(
                API_ENDPOINTS.ADMIN.QUESTION_ANSWER(question.id),
                { answer },
            );
            // Answering publishes, so say so — otherwise staff go looking for a
            // second button that does not exist.
            toast.success(
                'Answer saved and published to the product page.',
                'Question answered',
            );
            setDrafts((prev) => {
                const next = { ...prev };
                delete next[question.id];
                return next;
            });
            router.reload({ only: ['questions', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not save that answer.');
        } finally {
            setBusyId(null);
        }
    };

    const setPublished = async (question, isPublished) => {
        setBusyId(question.id);

        try {
            await axiosInstance.patch(
                API_ENDPOINTS.ADMIN.QUESTION_PUBLISH(question.id),
                { is_published: isPublished },
            );
            toast.success(
                isPublished
                    ? 'Question is now on the product page.'
                    : 'Question hidden from the product page.',
            );
            router.reload({ only: ['questions', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not update that question.');
        } finally {
            setBusyId(null);
        }
    };

    const remove = async (question) => {
        if (
            !window.confirm(
                `Permanently delete this question from ${question.name}? This cannot be undone.`,
            )
        ) {
            return;
        }

        setBusyId(question.id);

        try {
            await axiosInstance.delete(
                API_ENDPOINTS.ADMIN.QUESTION_ITEM(question.id),
            );
            toast.success('Question deleted.');
            router.reload({ only: ['questions', 'counts'] });
        } catch (err) {
            toast.error(err?.message || 'Could not delete that question.');
        } finally {
            setBusyId(null);
        }
    };

    return (
        <AdminLayout
            title="Product Questions"
            subtitle="Answer what shoppers ask before they buy"
        >
            <Head title="Product Questions" />

            <div className="admin-page-container">
                <div className="admin-settings-tabs-bar">
                    {TABS.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            className={`admin-tab-btn ${
                                (filters.filter || 'unanswered') === tab.key
                                    ? 'active'
                                    : ''
                            }`}
                            onClick={() =>
                                router.get(
                                    API_ENDPOINTS.ADMIN.QUESTIONS,
                                    { filter: tab.key },
                                    { preserveState: true },
                                )
                            }
                        >
                            {tab.label}
                            {counts[tab.key] !== undefined && (
                                <span className="admin-tab-count">
                                    {' '}
                                    ({counts[tab.key]})
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {rows.length === 0 ? (
                    <div className="admin-card">
                        <EmptyState
                            icon={MessageSquare}
                            title="Nothing waiting"
                            description="Questions shoppers ask on a product page arrive here for an answer."
                        />
                    </div>
                ) : (
                    <>
                        <p className="admin-field-hint aq-summary">
                            {counts.unanswered ?? 0} awaiting an answer ·{' '}
                            {counts.unpublished ?? 0} not published
                        </p>

                        <ul className="aq-list">
                            {rows.map((q) => {
                                const busy = busyId === q.id;
                                const draft = draftFor(q);

                                return (
                                    <li key={q.id} className="aq-card">
                                        <header className="aq-head">
                                            <div className="aq-product">
                                                <strong>
                                                    {q.product?.name ||
                                                        'Removed product'}
                                                </strong>
                                                {q.product?.slug && (
                                                    <a
                                                        href={`/products/${q.product.slug}`}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        View page
                                                        <ExternalLink
                                                            size={11}
                                                        />
                                                    </a>
                                                )}
                                            </div>

                                            <span
                                                className={`badge ${q.is_published ? 'badge-active' : 'badge-expired'}`}
                                            >
                                                {q.is_published
                                                    ? 'Published'
                                                    : 'Not published'}
                                            </span>
                                        </header>

                                        <blockquote className="aq-question">
                                            {q.question}
                                        </blockquote>

                                        <p className="aq-asker">
                                            <UserRound size={12} />
                                            {q.name}
                                            <span aria-hidden="true">·</span>
                                            {q.created_at?.slice(0, 10)}
                                        </p>

                                        {q.answer && !drafts[q.id] && (
                                            <div className="aq-answered">
                                                <CheckCircle2 size={13} />
                                                <div>
                                                    <p>{q.answer}</p>
                                                    {q.answered_by_name && (
                                                        <small>
                                                            Answered by{' '}
                                                            {q.answered_by_name}
                                                        </small>
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                        <div className="aq-reply">
                                            <FormInput
                                                id={`answer-${q.id}`}
                                                name={`answer-${q.id}`}
                                                type="textarea"
                                                rows={3}
                                                label={
                                                    q.answer
                                                        ? 'Edit the answer'
                                                        : 'Your answer'
                                                }
                                                value={draft}
                                                onChange={(e) =>
                                                    setDraft(
                                                        q.id,
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Answer from the showroom…"
                                                helperText="Answering publishes it on the product page."
                                            />

                                            <div className="aq-actions">
                                                <Button
                                                    icon={Send}
                                                    loading={busy}
                                                    disabled={
                                                        busy ||
                                                        draft.trim().length < 2
                                                    }
                                                    onClick={() =>
                                                        submitAnswer(q)
                                                    }
                                                >
                                                    {q.answer
                                                        ? 'Update answer'
                                                        : 'Answer & publish'}
                                                </Button>

                                                <Button
                                                    variant="secondary"
                                                    disabled={busy}
                                                    icon={
                                                        q.is_published
                                                            ? EyeOff
                                                            : Eye
                                                    }
                                                    onClick={() =>
                                                        setPublished(
                                                            q,
                                                            !q.is_published,
                                                        )
                                                    }
                                                >
                                                    {q.is_published
                                                        ? 'Hide'
                                                        : 'Publish'}
                                                </Button>

                                                {/* Quiet, and on the far side:
                                                    deleting is the one thing
                                                    here that cannot be undone,
                                                    so it should not sit under
                                                    the thumb of somebody
                                                    working through a queue. */}
                                                <button
                                                    type="button"
                                                    className="aq-delete"
                                                    disabled={busy}
                                                    onClick={() => remove(q)}
                                                >
                                                    <Trash2 size={13} />
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>

                        <Pagination
                            links={questions.links}
                            from={questions.from}
                            to={questions.to}
                            total={questions.total}
                        />
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
