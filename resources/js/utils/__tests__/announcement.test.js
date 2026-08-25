import { describe, it, expect } from 'vitest';
import { splitAnnouncement } from '../announcement';

describe('splitAnnouncement', () => {
    it('holds the heading back and scrolls the rest', () => {
        const { label, message } = splitAnnouncement(
            '⚡ Flash Deals Live: Save up to 40% OFF on Gaming Laptops.',
        );

        expect(label).toBe('⚡ Flash Deals Live:');
        expect(message).toBe('Save up to 40% OFF on Gaming Laptops.');
    });

    it('scrolls everything when there is no heading', () => {
        const { label, message } = splitAnnouncement(
            'Free delivery across all 64 districts this week',
        );

        expect(label).toBe('');
        expect(message).toBe('Free delivery across all 64 districts this week');
    });

    /* A colon this far in is punctuation, not a label. */
    it('does not treat a colon mid-sentence as a heading', () => {
        const text =
            'Save up to 40% off every graphics card in stock right now: this week only.';
        const { label, message } = splitAnnouncement(text);

        expect(label).toBe('');
        expect(message).toBe(text);
    });

    it('keeps a heading with nothing after it as the message', () => {
        const { label, message } = splitAnnouncement('Flash Deals Live:');

        expect(label).toBe('');
        expect(message).toBe('Flash Deals Live:');
    });

    it('survives an empty or missing announcement', () => {
        expect(splitAnnouncement('')).toEqual({ label: '', message: '' });
        expect(splitAnnouncement(undefined)).toEqual({ label: '', message: '' });
        expect(splitAnnouncement(null)).toEqual({ label: '', message: '' });
    });

    it('trims the join so the heading does not sit against the message', () => {
        const { label, message } = splitAnnouncement('  Notice :   Body text ');

        expect(label).toBe('Notice :');
        expect(message).toBe('Body text');
    });
});
