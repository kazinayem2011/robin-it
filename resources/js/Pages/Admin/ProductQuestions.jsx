import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import DataTable from '../../Components/DataTable';
import FormInput from '../../Components/FormInput';
import { toast } from '../../Components/Toast';
import { API_ENDPOINTS } from '../../constants/endpoints';
import axiosInstance from '../../services/axiosInstance';
import { MessageSquare, Send, Eye, EyeOff, Trash2 } from 'lucide-react';

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

    const columns = [
        {
            key: 'product',
            header: 'Product',
            render: (q) => (
                <div className="admin-question-product">
                    <span className="font-semibold text-sm">
                        {q.product?.name || 'Removed product'}
                    </span>
                    {q.product?.slug && (
                        <a
                            href={`/products/${q.product.slug}`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            View page
                        </a>
                    )}
                </div>
            ),
        },
        {
            key: 'question',
            header: 'Question',
            render: (q) => (
                <div className="admin-question-cell">
                    <p>{q.question}</p>
                    <small>
                        {q.name} · {q.created_at?.slice(0, 10)}
                    </small>
                </div>
            ),
        },
        {
            key: 'answer',
            header: 'Answer',
            render: (q) => (
                <div className="admin-question-answer">
                    <FormInput
                        id={`answer-${q.id}`}
                        name={`answer-${q.id}`}
                        type="textarea"
                        rows={3}
                        value={draftFor(q)}
                        onChange={(e) => setDraft(q.id, e.target.value)}
                        placeholder="Answer from the showroom…"
                    />
                    <Button
                        size="sm"
                        icon={Send}
                        disabled={busyId === q.id}
                        onClick={() => submitAnswer(q)}
                    >
                        {q.answer ? 'Update answer' : 'Answer & publish'}
                    </Button>
                    {q.answered_by_name && (
                        <small className="admin-question-answered-by">
                            Answered by {q.answered_by_name}
                        </small>
                    )}
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (q) => (
                <span
                    className={`badge ${q.is_published ? 'badge-new' : 'badge-hot'}`}
                >
                    {q.is_published ? 'Published' : 'Hidden'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            render: (q) => (
                <div className="admin-question-actions">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={busyId === q.id}
                        icon={q.is_published ? EyeOff : Eye}
                        onClick={() => setPublished(q, !q.is_published)}
                    >
                        {q.is_published ? 'Hide' : 'Publish'}
                    </Button>
                    <Button
                        variant="danger"
                        size="sm"
                        disabled={busyId === q.id}
                        icon={Trash2}
                        onClick={() => remove(q)}
                    >
                        Delete
                    </Button>
                </div>
            ),
        },
    ];

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

                <DataTable
                    title="Questions"
                    subtitle={`${counts.unanswered ?? 0} awaiting an answer · ${counts.unpublished ?? 0} not published`}
                    columns={columns}
                    data={questions}
                    emptyIcon={MessageSquare}
                    emptyTitle="No questions yet"
                    emptyDescription="Questions shoppers ask on a product page arrive here for an answer."
                />
            </div>
        </AdminLayout>
    );
}
