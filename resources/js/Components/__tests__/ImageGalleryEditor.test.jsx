import React, { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import ImageGalleryEditor from '../ImageGalleryEditor';

/**
 * Choosing which photo leads.
 *
 * The gallery has no other source of truth for it: whichever photo is first is
 * the one flagged primary, and that flag is what the card, the cart and the
 * order all read. So the star has to actually move the photo, and the move has
 * to survive being handed back as the new `images` prop — a controlled
 * component that drops its own change looks exactly like a dead button.
 */
const PHOTOS = [
    { id: 1, image_path: '/img/a.jpg', alt_text: '16GB A', is_primary: true },
    { id: 2, image_path: '/img/b.jpg', alt_text: '16GB B', is_primary: false },
];

const star = (n) =>
    screen.getByRole('button', { name: `Make photo ${n} the main photo` });

describe('ImageGalleryEditor', () => {
    it('marks the first photo as the main one', () => {
        render(<ImageGalleryEditor images={PHOTOS} onChange={() => {}} />);

        expect(screen.getByText('Main')).toBeTruthy();
        // Already leading, so there is nothing to promote.
        expect(star(1).disabled).toBe(true);
        expect(star(2).disabled).toBe(false);
    });

    it('promotes the chosen photo to the front and flags it', async () => {
        const onChange = vi.fn();
        render(<ImageGalleryEditor images={PHOTOS} onChange={onChange} />);

        await userEvent.click(star(2));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange.mock.calls[0][0]).toEqual([
            {
                id: 2,
                image_path: '/img/b.jpg',
                alt_text: '16GB B',
                is_primary: true,
            },
            {
                id: 1,
                image_path: '/img/a.jpg',
                alt_text: '16GB A',
                is_primary: false,
            },
        ]);
    });

    /**
     * The one that matters: the component is controlled, so promoting is only
     * real if the parent's new prop shows it. This is the loop the admin form
     * runs — onChange, then re-render with what came back.
     */
    it('shows the promotion once the parent hands it back', async () => {
        const Harness = () => {
            const [images, setImages] = useState(PHOTOS);

            return <ImageGalleryEditor images={images} onChange={setImages} />;
        };

        render(<Harness />);

        expect(screen.getByAltText('16GB A')).toBeTruthy();
        await userEvent.click(star(2));

        // The badge now sits on B, and B's own star is the disabled one.
        const thumbs = screen.getAllByRole('img');
        expect(thumbs[0].getAttribute('alt')).toBe('16GB B');
        expect(star(1).disabled).toBe(true);
    });

    /* Reordering by hand is the other way to change which leads. */
    it('re-flags the lead after an arrow move', async () => {
        const onChange = vi.fn();
        render(<ImageGalleryEditor images={PHOTOS} onChange={onChange} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Move photo 2 earlier' }),
        );

        const next = onChange.mock.calls[0][0];
        expect(next[0].id).toBe(2);
        expect(next[0].is_primary).toBe(true);
        expect(next[1].is_primary).toBe(false);
    });

    it('keeps a lead photo after the one in front of it is removed', async () => {
        const onChange = vi.fn();
        render(<ImageGalleryEditor images={PHOTOS} onChange={onChange} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Remove photo 1' }),
        );

        const next = onChange.mock.calls[0][0];
        expect(next).toHaveLength(1);
        expect(next[0].id).toBe(2);
        expect(next[0].is_primary).toBe(true);
    });
});
