import React, { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import ImageLightbox from '../ImageLightbox';

const PHOTOS = ['/a.jpg', '/b.jpg', '/c.jpg'];

/**
 * The product's photos, large enough to look at.
 *
 * The gallery's main image was about 405px wide and the largest view the shop
 * had — while growing on hover, so it advertised a click it did not answer.
 */
describe('ImageLightbox', () => {
    /* Held in state, as the page holds it, so moving is really observable. */
    const Harness = ({ start = 0, onClose = () => {} }) => {
        const [index, setIndex] = useState(start);

        return (
            <ImageLightbox
                images={PHOTOS}
                index={index}
                alt="A Laptop"
                onIndexChange={setIndex}
                onClose={onClose}
            />
        );
    };

    const shown = () =>
        document.querySelector('.lightbox-figure img')?.getAttribute('src');

    beforeEach(() => {
        document.body.style.overflow = 'unset';
    });

    it('opens on the photo it was given', () => {
        render(<Harness start={1} />);

        expect(shown()).toBe('/b.jpg');
        expect(screen.getByText('2 of 3')).toBeTruthy();
    });

    it('moves with the arrows', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.click(screen.getByLabelText('Next photo'));
        expect(shown()).toBe('/b.jpg');

        await user.click(screen.getByLabelText('Previous photo'));
        expect(shown()).toBe('/a.jpg');
    });

    it('moves with the arrow keys', () => {
        render(<Harness />);

        fireEvent.keyDown(window, { key: 'ArrowRight' });
        expect(shown()).toBe('/b.jpg');

        fireEvent.keyDown(window, { key: 'ArrowLeft' });
        expect(shown()).toBe('/a.jpg');
    });

    /* The last photo's "next" is a dead end otherwise. */
    it('wraps at both ends', () => {
        render(<Harness />);

        fireEvent.keyDown(window, { key: 'ArrowLeft' });
        expect(shown()).toBe('/c.jpg');

        fireEvent.keyDown(window, { key: 'ArrowRight' });
        expect(shown()).toBe('/a.jpg');
    });

    it('jumps from the thumbnails', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.click(screen.getByLabelText('Photo 3'));

        expect(shown()).toBe('/c.jpg');
        expect(screen.getByLabelText('Photo 3')).toHaveAttribute(
            'aria-current',
            'true',
        );
    });

    // ── the three ways out, all of which somebody tries ──────────────────────

    it('closes on Escape', () => {
        const onClose = vi.fn();
        render(<Harness onClose={onClose} />);

        fireEvent.keyDown(window, { key: 'Escape' });

        expect(onClose).toHaveBeenCalled();
    });

    it('closes on the backdrop', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<Harness onClose={onClose} />);

        await user.click(document.querySelector('.lightbox-backdrop'));

        expect(onClose).toHaveBeenCalled();
    });

    it('closes on the button', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<Harness onClose={onClose} />);

        await user.click(screen.getByLabelText('Close photos'));

        expect(onClose).toHaveBeenCalled();
    });

    /* Clicking the photograph itself must not close it. */
    it('stays open when the photo is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<Harness onClose={onClose} />);

        await user.click(document.querySelector('.lightbox-figure'));

        expect(onClose).not.toHaveBeenCalled();
    });

    it('stays open when a thumbnail is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<Harness onClose={onClose} />);

        await user.click(screen.getByLabelText('Photo 2'));

        expect(onClose).not.toHaveBeenCalled();
    });

    // ── keyboard and page state ──────────────────────────────────────────────

    it('takes focus, so the keyboard is not left behind the backdrop', () => {
        render(<Harness />);

        expect(document.activeElement).toBe(
            screen.getByLabelText('Close photos'),
        );
    });

    it('gives focus back to what opened it', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        const { unmount } = render(<Harness />);
        unmount();

        expect(document.activeElement).toBe(opener);
        opener.remove();
    });

    it('stops the page behind it scrolling, and lets it again', () => {
        const { unmount } = render(<Harness />);

        expect(document.body.style.overflow).toBe('hidden');

        unmount();
        expect(document.body.style.overflow).toBe('unset');
    });

    // ── the small cases ──────────────────────────────────────────────────────

    /* One photo needs no arrows, no count and no strip. */
    it('offers no way to move when there is one photo', () => {
        render(
            <ImageLightbox
                images={['/only.jpg']}
                index={0}
                alt="A Laptop"
                onClose={vi.fn()}
                onIndexChange={vi.fn()}
            />,
        );

        expect(screen.queryByLabelText('Next photo')).toBeNull();
        expect(screen.queryByText(/of 1/)).toBeNull();
        expect(document.querySelector('.lightbox-thumbs')).toBeNull();
    });

    it('draws nothing at all with no photos', () => {
        const { container } = render(
            <ImageLightbox images={[]} index={0} onClose={vi.fn()} />,
        );

        expect(container.textContent).toBe('');
    });

    it('says what it is', () => {
        render(<Harness />);

        expect(
            screen.getByRole('dialog', { name: /A Laptop — photos/ }),
        ).toBeTruthy();
    });
});
