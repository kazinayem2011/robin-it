import React, { useState } from 'react';

import StarRating from './StarRating';
import FormInput from './FormInput';
import Button from './Button';
import './ReviewForm.css';

/**
 * Reusable Product Review Submission Form Component
 *
 * @param {Function} onSubmit - Async submission callback receiving { rating, author_name, title, comment }
 * @param {boolean} [loading=false] - Submission loading state
 * @param {string} [title="Write a Verified Review"] - Form heading
 */
export default function ReviewForm({
    onSubmit,
    loading = false,
    title = 'Write a Verified Review',
}) {
    const [rating, setRating] = useState(5);
    const [authorName, setAuthorName] = useState('');
    const [reviewTitle, setReviewTitle] = useState('');
    const [comment, setComment] = useState('');

    const handleSubmit = async (e) => {
        e.preventDefault();
        await onSubmit({
            rating,
            author_name: authorName,
            title: reviewTitle,
            comment,
        });
        setReviewTitle('');
        setComment('');
    };

    return (
        <div className="write-review-card">
            <h4>{title}</h4>
            <form onSubmit={handleSubmit} className="review-form">
                <div className="review-form-rating-row">
                    <label className="review-form-label">Your Rating:</label>
                    <StarRating
                        value={rating}
                        onChange={setRating}
                        interactive
                        size={18}
                    />
                </div>

                <div className="review-form-row">
                    <FormInput
                        id="author_name"
                        label="Your Name"
                        placeholder="e.g. Tanvir Ahmed"
                        value={authorName}
                        onChange={(e) => setAuthorName(e.target.value)}
                        required
                    />
                    <FormInput
                        id="review_title"
                        label="Headline / Summary"
                        placeholder="e.g. Outstanding Performance & Fast Shipping"
                        value={reviewTitle}
                        onChange={(e) => setReviewTitle(e.target.value)}
                    />
                </div>

                <FormInput
                    id="review_comment"
                    type="textarea"
                    label="Review Description"
                    placeholder="Share your experience regarding performance, build quality, thermals, etc..."
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    rows={3}
                    required
                />

                <Button type="submit" variant="primary" loading={loading}>
                    Submit Review
                </Button>
            </form>
        </div>
    );
}
