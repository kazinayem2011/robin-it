import { describe, it, expect, vi, afterEach } from 'vitest';
import { scrollBehavior } from '../scroll';

/** Stand in for a system that has, or has not, asked for less movement. */
const setReducedMotion = (reduce) => {
    window.matchMedia = vi.fn((query) => ({
        matches: reduce && query === '(prefers-reduced-motion: reduce)',
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }));
};

afterEach(() => {
    vi.restoreAllMocks();
});

describe('scrollBehavior', () => {
    it('animates for a reader who has not asked otherwise', () => {
        setReducedMotion(false);
        expect(scrollBehavior()).toBe('smooth');
    });

    /* The bug this exists to prevent: an explicit 'smooth' argument beats the
     * stylesheet, so the media query alone never reached these scrolls. */
    it('jumps for a reader who asked for reduced motion', () => {
        setReducedMotion(true);
        expect(scrollBehavior()).toBe('auto');
    });

    it('asks the media query again each time, so a change mid-session lands', () => {
        setReducedMotion(false);
        expect(scrollBehavior()).toBe('smooth');
        setReducedMotion(true);
        expect(scrollBehavior()).toBe('auto');
    });

    it('animates rather than throwing where matchMedia is unavailable', () => {
        window.matchMedia = undefined;
        expect(scrollBehavior()).toBe('smooth');
    });
});
