import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import ReviewForm from '../ReviewForm';

/*
 * A refused review kept nothing: the form emptied the headline and the
 * description whatever the server said, so a review turned away for being
 * too short had to be written again from the start.
 */
const fill = () => {
    fireEvent.change(screen.getByLabelText(/Your Name/), {
        target: { value: 'Rahim' },
    });
    fireEvent.change(screen.getByLabelText(/Headline/), {
        target: { value: 'Light and quick' },
    });
    fireEvent.change(screen.getByLabelText(/Review Description/), {
        target: { value: 'Ok' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Submit Review/ }));
};

describe('ReviewForm', () => {
    it('keeps what was typed when the review is refused', async () => {
        const onSubmit = vi.fn().mockResolvedValue(false);
        render(<ReviewForm onSubmit={onSubmit} />);
        fill();
        await waitFor(() => expect(onSubmit).toHaveBeenCalled());
        expect(screen.getByLabelText(/Headline/)).toHaveValue(
            'Light and quick',
        );
        expect(screen.getByLabelText(/Review Description/)).toHaveValue('Ok');
    });

    it('clears the headline and description once it is accepted', async () => {
        const onSubmit = vi.fn().mockResolvedValue(true);
        render(<ReviewForm onSubmit={onSubmit} />);
        fill();
        await waitFor(() =>
            expect(screen.getByLabelText(/Headline/)).toHaveValue(''),
        );
        expect(screen.getByLabelText(/Review Description/)).toHaveValue('');
    });
});
