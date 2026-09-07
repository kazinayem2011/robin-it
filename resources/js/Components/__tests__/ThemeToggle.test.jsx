import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import ThemeToggle from '../ThemeToggle';
import useAppStore from '../../store/useAppStore';

/**
 * Light or dark, starting light.
 *
 * Two states, so the strip form is a switch in the ordinary sense rather than
 * something you press repeatedly to find the option you wanted. The icon shows
 * the theme you are in; the accessible name says what pressing it will do,
 * which is the half an icon cannot carry on its own.
 */
describe('ThemeToggle', () => {
    beforeEach(() => {
        window.localStorage.clear();
        document.documentElement.removeAttribute('data-theme');
        useAppStore.setState({ theme: 'light' });
    });

    afterEach(() => vi.restoreAllMocks());

    describe('the switch, in the top bar and the footer', () => {
        const button = () => screen.getByRole('button');

        it('names the theme it is on and the one it will move to', () => {
            render(<ThemeToggle />);

            // Icon only, so the accessible name is the whole interface.
            expect(button()).toHaveAccessibleName(
                /theme: light\. switch to dark\./i,
            );
        });

        it('flips to dark and back again', async () => {
            const user = userEvent.setup();
            render(<ThemeToggle />);

            await user.click(button());
            expect(useAppStore.getState().theme).toBe('dark');
            expect(button()).toHaveAccessibleName(
                /theme: dark\. switch to light\./i,
            );

            await user.click(button());
            expect(useAppStore.getState().theme).toBe('light');
        });

        it('paints the document as it goes', async () => {
            const user = userEvent.setup();
            render(<ThemeToggle />);

            await user.click(button());

            expect(document.documentElement.getAttribute('data-theme')).toBe(
                'dark',
            );
        });

        it('remembers the choice for the next visit', async () => {
            const user = userEvent.setup();
            render(<ThemeToggle />);

            await user.click(button());

            expect(window.localStorage.getItem('robinit.theme.v1')).toBe(
                'dark',
            );
        });
    });

    /*
     * The drawer form. On a phone the top bar's right-hand group is hidden and
     * the footer is a page away, so this is the only copy above the fold — it
     * gets the whole width and shows both options outright.
     */
    describe('the flat form, in the phone drawer', () => {
        it('shows both options without anything needing to be pressed first', () => {
            render(<ThemeToggle variant="inline" />);

            expect(
                screen.getAllByRole('radio').map((o) => o.textContent),
            ).toEqual(['Light', 'Dark']);
        });

        it('marks the one that is showing', () => {
            useAppStore.setState({ theme: 'dark' });
            render(<ThemeToggle variant="inline" />);

            expect(screen.getByRole('radio', { name: /dark/i })).toBeChecked();
            expect(
                screen.getByRole('radio', { name: /light/i }),
            ).not.toBeChecked();
        });

        it('picks a theme in one press', async () => {
            const user = userEvent.setup();
            render(<ThemeToggle variant="inline" />);

            await user.click(screen.getByRole('radio', { name: /dark/i }));

            expect(document.documentElement.getAttribute('data-theme')).toBe(
                'dark',
            );
        });

        /* Pressing the one already chosen must not toggle away from it. */
        it('is idempotent on the option already showing', async () => {
            const user = userEvent.setup();
            render(<ThemeToggle variant="inline" />);

            await user.click(screen.getByRole('radio', { name: /light/i }));

            expect(useAppStore.getState().theme).toBe('light');
        });
    });

    /*
     * All three copies are on the same page — the top bar, the footer, and the
     * drawer on a phone — so they read one store rather than each holding their
     * own state. Two controls disagreeing about what is showing is the bug this
     * prevents.
     */
    it('keeps every copy on the page in step', async () => {
        const user = userEvent.setup();
        render(
            <>
                <ThemeToggle variant="bar" />
                <ThemeToggle variant="inline" />
            </>,
        );

        await user.click(screen.getByRole('radio', { name: /dark/i }));

        expect(screen.getByRole('button')).toHaveAccessibleName(
            /theme: dark\. switch to light\./i,
        );

        // …and back the other way.
        await user.click(screen.getByRole('button'));

        expect(screen.getByRole('radio', { name: /light/i })).toBeChecked();
    });
});
