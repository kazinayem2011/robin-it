import React from 'react';
import { Star } from 'lucide-react';

/**
 * StarRating — Reusable star display + interactive picker component.
 *
 * Display mode  (interactive=false, default):
 *   Renders N filled/empty stars for a given numeric rating.
 *   <StarRating value={4.5} size={14} />
 *
 * Picker mode   (interactive=true):
 *   Renders a radio-group of clickable stars. Calls onChange(newRating).
 *   <StarRating value={rating} onChange={setRating} interactive size={18} />
 *
 * @param {number}   value       - Current rating value (1–5).
 * @param {Function} [onChange]  - Picker callback; required when interactive=true.
 * @param {boolean}  [interactive=false] - Enable click-to-rate mode.
 * @param {number}   [size=14]   - Icon pixel size.
 * @param {string}   [className] - Extra class for the wrapper element.
 */
export default function StarRating({
    value = 0,
    onChange,
    interactive = false,
    size = 14,
    className = '',
}) {
    if (interactive) {
        return (
            <div
                className={`star-rating-picker ${className}`}
                role="radiogroup"
                aria-label="Select star rating"
            >
                {[1, 2, 3, 4, 5].map((num) => (
                    <button
                        key={num}
                        type="button"
                        role="radio"
                        aria-checked={value === num}
                        aria-label={`${num} Star`}
                        className={`star-pick-btn ${value >= num ? 'selected' : ''}`}
                        onClick={() => onChange && onChange(num)}
                    >
                        <Star
                            size={size}
                            className={
                                value >= num ? 'star-filled' : 'star-empty'
                            }
                        />
                    </button>
                ))}
            </div>
        );
    }

    // Display-only mode
    return (
        <div
            className={`star-display-row ${className}`}
            aria-label={`Rating: ${value} out of 5`}
        >
            {[1, 2, 3, 4, 5].map((num) => (
                <Star
                    key={num}
                    size={size}
                    className={
                        num <= Math.round(value) ? 'star-filled' : 'star-empty'
                    }
                />
            ))}
        </div>
    );
}
