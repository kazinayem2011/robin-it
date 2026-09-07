import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    applyTheme,
    readThemeChoice,
    writeThemeChoice,
    DEFAULT_THEME,
    THEMES,
} from '../theme';

/**
 * Light or dark, and light until somebody says otherwise.
 *
 * The machine's own preference is deliberately not consulted: the storefront
 * is a light shop, and a customer with a dark laptop should not have that
 * decided for them on a first visit. Dark is a choice made here, and it sticks.
 *
 * Nothing here may throw. Storage is unavailable in a private window and in a
 * browser with site data switched off, and a shop that will not render because
 * it could not read a preference is a far worse failure than a light one.
 */
describe('theme', () => {
    beforeEach(() => {
        window.localStorage.clear();
        document.documentElement.removeAttribute('data-theme');
        document.documentElement.style.colorScheme = '';
    });

    afterEach(() => vi.restoreAllMocks());

    describe('the stored theme', () => {
        it('offers exactly light and dark', () => {
            expect(THEMES).toEqual(['light', 'dark']);
        });

        it('is light for somebody who has never picked one', () => {
            expect(readThemeChoice()).toBe('light');
            expect(DEFAULT_THEME).toBe('light');
        });

        it.each(THEMES)('remembers %s', (choice) => {
            writeThemeChoice(choice);

            expect(readThemeChoice()).toBe(choice);
        });

        it('ignores a value that is not a theme', () => {
            window.localStorage.setItem('robinit.theme.v1', 'chartreuse');

            expect(readThemeChoice()).toBe('light');
        });

        /*
         * This once stored 'system'. Somebody who chose it before it was
         * retired still has it in their browser, and must land on light rather
         * than on an attribute the stylesheet does not recognise.
         */
        it('retires the system setting an earlier visit may have left behind', () => {
            window.localStorage.setItem('robinit.theme.v1', 'system');

            expect(readThemeChoice()).toBe('light');
        });

        it('refuses to store a value that is not a theme', () => {
            writeThemeChoice('dark');
            writeThemeChoice('system');

            expect(readThemeChoice()).toBe('dark');
        });

        it('falls back to light when storage cannot be read', () => {
            vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
                throw new Error('site data blocked');
            });

            expect(readThemeChoice()).toBe('light');
        });

        it('does not throw when storage cannot be written', () => {
            vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
                throw new Error('quota exceeded');
            });

            expect(() => writeThemeChoice('dark')).not.toThrow();
        });

        /*
         * The one thing the machine could still have changed under us. Whatever
         * the OS is set to, a fresh visitor gets light.
         */
        it('never asks the machine what it prefers', () => {
            const matchMedia = vi.fn().mockReturnValue({
                matches: true,
                addEventListener: () => {},
                removeEventListener: () => {},
            });
            window.matchMedia = matchMedia;

            expect(readThemeChoice()).toBe('light');
            expect(applyTheme(readThemeChoice())).toBe('light');
            expect(matchMedia).not.toHaveBeenCalled();
        });
    });

    describe('applying a theme', () => {
        it.each(THEMES)('stamps %s on the document', (choice) => {
            expect(applyTheme(choice)).toBe(choice);
            expect(document.documentElement.getAttribute('data-theme')).toBe(
                choice,
            );
        });

        it('sets colour-scheme so the browser paints its own parts to match', () => {
            applyTheme('dark');

            expect(document.documentElement.style.colorScheme).toBe('dark');
        });

        it('falls back to light rather than stamping something unrecognised', () => {
            expect(applyTheme('system')).toBe('light');
            expect(document.documentElement.getAttribute('data-theme')).toBe(
                'light',
            );
        });

        it('repaints the phone status bar to the page background', () => {
            const meta = document.createElement('meta');
            meta.setAttribute('name', 'theme-color');
            meta.setAttribute('content', '#f1f4f8');
            document.head.appendChild(meta);

            applyTheme('dark');
            expect(meta.getAttribute('content')).toBe('#0a0e17');

            applyTheme('light');
            expect(meta.getAttribute('content')).toBe('#f1f4f8');

            meta.remove();
        });

        it('does not mind the meta tag being absent', () => {
            expect(() => applyTheme('dark')).not.toThrow();
        });
    });
});
