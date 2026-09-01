import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import DataTable from '../../Components/DataTable';
import Modal from '../../Components/Modal';
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
    /*
     * The question being answered, and what has been typed for it.
     *
     * One at a time, because the form is a modal: a textarea per row put a
     * three-line box inside a table cell, which made every row two hundred
     * pixels tall and left the send button hanging below its own cell.
     */
    const [answering, setAnswering] = useState(null);
    const [draft, setDraft] = useState('');

    const openAnswer = (question) => {
        setAnswering(question);
        setDraft(question.answer ?? '');
    };

    const closeAnswer = () => {
        setAnswering(null);
        setDraft('');
    };

    const submitAnswer = async () => {
        const question = answering;
        const answer = draft.trim();

        if (!question || answer.length < 2) {
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
            closeAnswer();
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
                <div className="aq-product">
                    <strong>{q.product?.name || 'Removed product'}</strong>
                    {q.product?.slug && (
                        <a
                            href={`/products/${q.product.slug}`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            View page
                            <ExternalLink size={11} />
                        </a>
                    )}
                </div>
            ),
        },
        {
            key: 'question',
            header: 'Question',
            render: (q) => (
                <div className="aq-cell">
                    {/* Clamped to two lines: a long question would otherwise
                        set the height of its whole row. The modal shows it
                        in full. */}
                    <p className="aq-text">{q.question}</p>
                    <span className="aq-meta">
                        <UserRound size={11} />
                        {q.name}
                        <span aria-hidden="true">·</span>
                        {q.created_at?.slice(0, 10)}
                    </span>
                </div>
            ),
        },
        {
            key: 'answer',
            header: 'Answer',
            render: (q) =>
                q.answer ? (
                    <div className="aq-cell">
                        <p className="aq-text is-answer">
                            <CheckCircle2 size={12} />
                            {q.answer}
                        </p>
                        {q.answered_by_name && (
                            <span className="aq-meta">
                                by {q.answered_by_name}
                            </span>
                        )}
                    </div>
                ) : (
                    <span className="aq-awaiting">Not answered yet</span>
                ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (q) => (
                <span
                    className={`badge ${q.is_published ? 'badge-active' : 'badge-expired'}`}
                >
                    {q.is_published ? 'Published' : 'Hidden'}
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (q) => (
                <div className="aq-actions">
                    <Button
                        size="sm"
                        icon={Send}
                        disabled={busyId === q.id}
                        onClick={() => openAnswer(q)}
                    >
                        {q.answer ? 'Edit' : 'Answer'}
                    </Button>
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title={
                            q.is_published
                                ? 'Hide from the product page'
                                : 'Publish to the product page'
                        }
                        disabled={busyId === q.id}
                        onClick={() => setPublished(q, !q.is_published)}
                    >
                        {q.is_published ? (
                            <EyeOff size={14} />
                        ) : (
                            <Eye size={14} />
                        )}
                    </button>
                    <button
                        type="button"
                        className="admin-table-icon-btn aq-delete"
                        title="Delete"
                        disabled={busyId === q.id}
                        onClick={() => remove(q)}
                    >
                        <Trash2 size={14} />
                    </button>
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
                    emptyTitle="Nothing waiting"
                    emptyDescription="Questions shoppers ask on a product page arrive here for an answer."
                />
            </div>

            {/* The answer form, with room to write in. */}
            <Modal
                isOpen={Boolean(answering)}
                onClose={closeAnswer}
                title={
                    answering?.answer
                        ? 'Edit the answer'
                        : 'Answer this question'
                }
                maxWidth="620px"
            >
                {answering && (
                    <>
                        <div className="aq-modal-product">
                            {answering.product?.name || 'Removed product'}
                        </div>

                        <blockquote className="aq-modal-question">
                            {answering.question}
                        </blockquote>

                        <p className="aq-modal-asker">
                            <UserRound size={12} />
                            {answering.name}
                            <span aria-hidden="true">·</span>
                            {answering.created_at?.slice(0, 10)}
                        </p>

                        <FormInput
                            id="question-answer"
                            name="question-answer"
                            type="textarea"
                            rows={5}
                            label="Your answer"
                            required
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            placeholder="Answer from the showroom…"
                            helperText="Saving publishes it on the product page."
                            autoFocus
                        />

                        <div className="admin-modal-actions">
                            <Button variant="secondary" onClick={closeAnswer}>
                                Cancel
                            </Button>
                            <Button
                                icon={Send}
                                loading={busyId === answering.id}
                                disabled={draft.trim().length < 2}
                                onClick={submitAnswer}
                            >
                                {answering.answer
                                    ? 'Update answer'
                                    : 'Answer & publish'}
                            </Button>
                        </div>
                    </>
                )}
            </Modal>
        </AdminLayout>
    );
}
