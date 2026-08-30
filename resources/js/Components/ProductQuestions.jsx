import React, { useState } from 'react';
import Button from './Button';
import FormInput from './FormInput';
import { toast } from './Toast';
import { MessageSquare } from 'lucide-react';

/**
 * Questions a shopper asks before buying.
 *
 * Distinct from reviews, which are written after the fact by people who have
 * already decided. A question is the thing standing between someone and a
 * purchase, so the form is short and does not require an account — asking for
 * one here loses the question and the sale with it.
 */
export default function ProductQuestions({ slug, questions = [], onAsked }) {
    const [question, setQuestion] = useState('');
    const [name, setName] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const submit = async (e) => {
        e.preventDefault();

        if (question.trim().length < 10) {
            toast.error(
                'Please write a little more so we can answer properly.',
                'Question too short',
            );
            return;
        }

        setSubmitting(true);

        try {
            const res = await fetch(`/api/products/${slug}/questions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        (document.cookie.match(/XSRF-TOKEN=([^;]+)/) ||
                            [])[1] || '',
                    ),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ question, name: name || undefined }),
            });

            if (!res.ok) throw new Error('Failed');

            setQuestion('');
            setName('');
            /* Deliberately not "your question is now live". It goes to a
               moderation queue, and telling someone to look for something that
               is not there yet reads as a bug. */
            toast.success(
                'Thanks — we will answer this shortly.',
                'Question received',
            );
            onAsked?.();
        } catch {
            toast.error('Could not send your question. Please try again.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="pdp-questions">
            {questions.length > 0 ? (
                <ul className="pdp-question-list">
                    {questions.map((q) => (
                        <li key={q.id} className="pdp-question">
                            <p className="pdp-question-text">
                                <span className="pdp-q-marker">Q</span>
                                {q.question}
                            </p>
                            {q.answer ? (
                                <p className="pdp-answer-text">
                                    <span className="pdp-a-marker">A</span>
                                    {q.answer}
                                </p>
                            ) : (
                                /* Shown rather than hidden: "asked, not yet
                                   answered" is information a shopper can act
                                   on, and hiding it makes the shop look as
                                   though nobody has ever asked anything. */
                                <p className="pdp-answer-pending">
                                    Awaiting an answer from our team.
                                </p>
                            )}
                            <span className="pdp-question-meta">
                                {q.name} · {q.asked_at}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : (
                <div className="pdp-questions-empty">
                    <MessageSquare size={22} />
                    <p>
                        No questions yet. Ask the first one — we answer from the
                        showroom.
                    </p>
                </div>
            )}

            <form className="pdp-question-form" onSubmit={submit}>
                <h4>Ask about this product</h4>
                <FormInput
                    id="question"
                    name="question"
                    type="textarea"
                    rows={3}
                    label="Your question"
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    placeholder="Does this take a second SSD?"
                />
                <FormInput
                    id="asker_name"
                    name="asker_name"
                    label="Your name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="Optional if you are signed in"
                />
                <Button type="submit" loading={submitting}>
                    Send question
                </Button>
            </form>
        </div>
    );
}
