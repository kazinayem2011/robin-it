import React, { useState } from 'react';
import Button from './Button';
import FormInput from './FormInput';
import { toast } from './Toast';
import { MessageSquare, Clock, UserRound } from 'lucide-react';

/**
 * Questions a shopper asks before buying.
 *
 * Distinct from reviews, which are written after the fact by people who have
 * already decided. A question is the thing standing between someone and a
 * purchase, so the form is short and does not require an account — asking for
 * one here loses the question and the sale with it.
 *
 * A signed-in shopper is not asked their name: the server already falls back to
 * the account, and a field labelled "Optional if you are signed in" left it to
 * the reader to work out whether they were.
 */
export default function ProductQuestions({
    slug,
    questions = [],
    onAsked,
    askingAs = '',
}) {
    const [question, setQuestion] = useState('');
    const [name, setName] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const signedIn = Boolean(askingAs);
    const tooShort = question.trim().length > 0 && question.trim().length < 10;

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
                <>
                    <h4 className="pdp-questions-heading">
                        {questions.length} question
                        {questions.length === 1 ? '' : 's'} about this product
                    </h4>

                    <ul className="pdp-question-list">
                        {questions.map((q) => (
                            <li key={q.id} className="pdp-question">
                                {/* Marker in its own column: inline, a wrapped
                                    question ran back underneath the badge and
                                    the two lines did not line up. */}
                                <div className="pdp-qa-row">
                                    <span className="pdp-q-marker">Q</span>
                                    <div className="pdp-qa-body">
                                        <p className="pdp-question-text">
                                            {q.question}
                                        </p>
                                        <span className="pdp-question-meta">
                                            <UserRound size={12} />
                                            {q.name}
                                            <span aria-hidden="true">·</span>
                                            {q.asked_at}
                                        </span>
                                    </div>
                                </div>

                                {q.answer ? (
                                    <div className="pdp-qa-row is-answer">
                                        <span className="pdp-a-marker">A</span>
                                        <div className="pdp-qa-body">
                                            <p className="pdp-answer-text">
                                                {q.answer}
                                            </p>
                                            <span className="pdp-question-meta">
                                                Robin&apos;s Computer
                                            </span>
                                        </div>
                                    </div>
                                ) : (
                                    /* Shown rather than hidden: "asked, not yet
                                       answered" is information a shopper can
                                       act on, and hiding it makes the shop look
                                       as though nobody has ever asked. */
                                    <span className="pdp-answer-pending">
                                        <Clock size={12} />
                                        Awaiting an answer from our team
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            ) : (
                <div className="pdp-questions-empty">
                    <MessageSquare size={26} />
                    <div>
                        <strong>No questions yet</strong>
                        <p>
                            Ask the first one — we answer from the showroom,
                            usually the same day.
                        </p>
                    </div>
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
                    required
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    placeholder="Does this take a second SSD?"
                    error={
                        tooShort
                            ? 'A few more words, so we can answer it properly.'
                            : ''
                    }
                    helperText={
                        tooShort
                            ? ''
                            : 'Answered by the showroom, then published here with the answer.'
                    }
                />

                {signedIn ? (
                    /* Named rather than asked for. The server uses the account
                       name when none is sent, so a field here only invites
                       somebody to contradict it. */
                    <p className="pdp-asking-as">
                        <UserRound size={14} />
                        Asking as <strong>{askingAs}</strong>
                    </p>
                ) : (
                    <FormInput
                        id="asker_name"
                        name="asker_name"
                        label="Your name"
                        required
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="So we know who we are answering"
                    />
                )}

                <Button
                    type="submit"
                    loading={submitting}
                    disabled={question.trim().length < 10}
                >
                    Send question
                </Button>
            </form>
        </div>
    );
}
