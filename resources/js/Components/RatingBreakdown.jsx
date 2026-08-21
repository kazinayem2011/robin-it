import React from 'react';
import { Star } from 'lucide-react';
import StarRating from './StarRating';
import './RatingBreakdown.css';

/**
 * Reusable Rating Breakdown & Score Summary Component
 *
 * @param {number} averageRating - Average rating score (e.g. 4.8)
 * @param {number} totalReviews - Total review count
 * @param {Object} breakdown - Breakdown map by star count: { 5: 20, 4: 5, 3: 2, 2: 0, 1: 0 }
 * @param {boolean} [showScoreBox=true] - Whether to show the big rating score box on the left
 * @param {string} [className=''] - Custom container class
 */
export default function RatingBreakdown({
    averageRating = 5,
    totalReviews = 0,
    breakdown = {},
    showScoreBox = true,
    className = '',
}) {
    const roundedRating = Math.round(averageRating || 5);

    return (
        <div className={`rating-breakdown-container ${className}`}>
            {showScoreBox && (
                <div className="rating-score-box">
                    <div className="rating-big-number">
                        {Number(averageRating || 5).toFixed(1)}
                    </div>
                    <StarRating
                        value={roundedRating}
                        size={18}
                        className="rating-stars-row"
                    />
                    <div className="rating-total-text">
                        Based on {totalReviews}{' '}
                        {totalReviews === 1 ? 'review' : 'reviews'}
                    </div>
                </div>
            )}

            <div className="rating-bars-box">
                {[5, 4, 3, 2, 1].map((stars) => {
                    const count = breakdown?.[stars] || 0;
                    const total = totalReviews || 1;
                    const pct = Math.min(
                        100,
                        Math.round((count / total) * 100),
                    );

                    return (
                        <div key={stars} className="rating-bar-row">
                            <span className="rating-star-label">
                                {stars}{' '}
                                <Star size={12} className="star-filled" />
                            </span>
                            <div className="rating-progress-track">
                                <div
                                    className="rating-progress-fill"
                                    style={{ width: `${pct}%` }}
                                ></div>
                            </div>
                            <span className="rating-count-num">{count}</span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
