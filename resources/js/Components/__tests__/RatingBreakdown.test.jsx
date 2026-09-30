import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import RatingBreakdown from '../RatingBreakdown';

/**
 * No reviews is no rating. A product nobody had reviewed showed "5.0" and five
 * gold stars over "Based on 0 reviews" — a score the shop had given itself.
 */
describe('RatingBreakdown', () => {
    it('shows no score when there are no reviews', () => {
        render(<RatingBreakdown averageRating={5} totalReviews={0} />);

        expect(screen.queryByText('5.0')).toBeNull();
        expect(screen.getByText('No reviews yet')).toBeTruthy();
    });

    it('shows the real average when there are reviews', () => {
        render(<RatingBreakdown averageRating={4.26} totalReviews={3} />);

        expect(screen.getByText('4.3')).toBeTruthy();
        expect(screen.getByText('Based on 3 reviews')).toBeTruthy();
    });
});
