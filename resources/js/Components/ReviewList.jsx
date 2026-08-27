import React from 'react';
import { Check, MessageSquare } from 'lucide-react';
import StarRating from './StarRating';
import './ReviewList.css';

/**
 * Reusable Customer Reviews Feed List Component
 *
 * @param {Array<Object>} reviews - List of review objects { id, author_name, is_verified_buyer, rating, title, comment, created_at }
 * @param {number} [totalReviews] - Total count of reviews
 */
export default function ReviewList({ reviews = [], totalReviews = 0 }) {
    const count = totalReviews || reviews.length;

    return (
        <div className="reviews-feed-list">
            <h4>Customer Feedback ({count})</h4>
            {reviews && reviews.length > 0 ? (
                <div className="review-items-stack">
                    {reviews.map((rev) => (
                        <div key={rev.id} className="review-item-card">
                            <div className="review-item-top">
                                <div className="review-author-info">
                                    <strong>{rev.author_name}</strong>
                                    {rev.is_verified_buyer && (
                                        <span className="verified-badge">
                                            <Check size={12} /> Verified Buyer
                                        </span>
                                    )}
                                </div>
                                <StarRating
                                    value={rev.rating}
                                    size={13}
                                    className="review-stars-pill"
                                />
                            </div>
                            {rev.title && (
                                <h5 className="review-title">{rev.title}</h5>
                            )}
                            <p className="review-comment">{rev.comment}</p>
                            {rev.created_at && (
                                <span className="review-date-text">
                                    {new Date(
                                        rev.created_at,
                                    ).toLocaleDateString('en-GB', {
                                        day: 'numeric',
                                        month: 'short',
                                        year: 'numeric',
                                    })}
                                </span>
                            )}
                        </div>
                    ))}
                </div>
            ) : (
                <div className="no-reviews-box">
                    <MessageSquare size={36} className="text-muted" />
                    <p>
                        No customer reviews yet. Be the first to share your
                        experience!
                    </p>
                </div>
            )}
        </div>
    );
}
