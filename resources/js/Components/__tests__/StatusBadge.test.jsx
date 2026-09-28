import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import StatusBadge from '../StatusBadge';

describe('StatusBadge', () => {
    /* A returned order read "Pending", as if it were still to be sent. */
    it('says Returned for a returned order', () => {
        render(<StatusBadge status="returned" />);
        expect(screen.getByText('Returned')).toBeInTheDocument();
        expect(screen.queryByText('Pending')).not.toBeInTheDocument();
    });

    it.each([
        ['pending', 'Pending'],
        ['processing', 'Processing'],
        ['shipped', 'Shipped'],
        ['delivered', 'Delivered'],
        ['cancelled', 'Cancelled'],
    ])('labels %s', (status, label) => {
        render(<StatusBadge status={status} />);
        expect(screen.getByText(label)).toBeInTheDocument();
    });
});
