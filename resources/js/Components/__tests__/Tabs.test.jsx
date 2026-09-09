import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import Tabs from '../Tabs';

/**
 * Tabs, across the top or down the side.
 *
 * Vertical is opt-in: the settings screen has seven of them, one called
 * "Announcement Ticker", and a row either wrapped or scrolled the rest out of
 * sight. Every other screen keeps the row it had, which is what these check.
 */
const TABS = [
    { key: 'general', label: 'General' },
    { key: 'sms', label: 'SMS' },
    { key: 'ticker', label: 'Announcement Ticker' },
];

const nav = () => document.querySelector('.reusable-tabs-nav');
const container = () => document.querySelector('.reusable-tabs-container');

describe('Tabs', () => {
    it('is a row unless asked otherwise', () => {
        render(<Tabs tabs={TABS} activeTab="general" onChange={() => {}} />);

        expect(container().className).toContain('orientation-horizontal');
        expect(nav().getAttribute('aria-orientation')).toBe('horizontal');
    });

    it('can be a column', () => {
        render(
            <Tabs
                tabs={TABS}
                activeTab="general"
                onChange={() => {}}
                orientation="vertical"
            />,
        );

        expect(container().className).toContain('orientation-vertical');
        expect(nav().getAttribute('aria-orientation')).toBe('vertical');
    });

    /* Orientation is presentation; it must not change what the tabs do. */
    it('reports the same choice either way', async () => {
        const user = userEvent.setup();

        for (const orientation of ['horizontal', 'vertical']) {
            const onChange = vi.fn();
            const { unmount } = render(
                <Tabs
                    tabs={TABS}
                    activeTab="general"
                    onChange={onChange}
                    orientation={orientation}
                />,
            );

            await user.click(screen.getByRole('tab', { name: 'SMS' }));

            expect(onChange).toHaveBeenCalledWith('sms');
            unmount();
        }
    });

    it('marks the current one for a screen reader', () => {
        render(
            <Tabs
                tabs={TABS}
                activeTab="sms"
                onChange={() => {}}
                orientation="vertical"
            />,
        );

        expect(screen.getByRole('tab', { name: 'SMS' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByRole('tab', { name: 'General' })).toHaveAttribute(
            'aria-selected',
            'false',
        );
    });

    it('keeps the variant alongside the orientation', () => {
        render(
            <Tabs
                tabs={TABS}
                activeTab="general"
                onChange={() => {}}
                variant="enclosed"
                orientation="vertical"
            />,
        );

        expect(container().className).toContain('variant-enclosed');
        expect(container().className).toContain('orientation-vertical');
    });

    /**
     * Navigation mode, for a row that moves you between sections which are all
     * on the page rather than switching which one is shown.
     *
     * The distinction is not cosmetic. `role="tab"` promises a panel that
     * appears when chosen and hides when not, and `aria-selected="false"`
     * claims the others are hidden — both untrue when every section is on
     * screen, so the markup has to change with the behaviour.
     */
    describe('as navigation', () => {
        const asNav = (activeTab = 'general') =>
            render(
                <Tabs
                    navigation
                    tabs={TABS}
                    activeTab={activeTab}
                    onChange={() => {}}
                />,
            );

        it('is a nav of links, not a tablist of buttons', () => {
            asNav();

            expect(nav().tagName).toBe('NAV');
            expect(nav().getAttribute('role')).toBeNull();
            expect(document.querySelectorAll('[role="tab"]')).toHaveLength(0);
            expect(
                document.querySelectorAll('a.reusable-tab-btn'),
            ).toHaveLength(TABS.length);
        });

        it('links to each section by its key, so it works without the script', () => {
            asNav();

            expect(
                screen.getByRole('link', { name: 'SMS' }).getAttribute('href'),
            ).toBe('#sms');
        });

        it('marks the section being read with aria-current, not aria-selected', () => {
            asNav('sms');

            const current = screen.getByRole('link', { name: 'SMS' });
            expect(current.getAttribute('aria-current')).toBe('true');
            expect(current.getAttribute('aria-selected')).toBeNull();

            expect(
                screen
                    .getByRole('link', { name: 'General' })
                    .getAttribute('aria-current'),
            ).toBeNull();
        });

        it('reports the section clicked without letting the page jump', async () => {
            const onChange = vi.fn();
            render(
                <Tabs
                    navigation
                    tabs={TABS}
                    activeTab="general"
                    onChange={onChange}
                />,
            );

            await userEvent.click(screen.getByRole('link', { name: 'SMS' }));

            expect(onChange).toHaveBeenCalledWith('sms');
            // preventDefault, so the browser does not also hard-jump to the
            // fragment and fight the smooth scroll the caller does.
            expect(window.location.hash).toBe('');
        });

        it('still renders badges', () => {
            render(
                <Tabs
                    navigation
                    tabs={[{ key: 'reviews', label: 'Reviews', badge: 7 }]}
                    activeTab="reviews"
                    onChange={() => {}}
                />,
            );

            expect(screen.getByText('7')).toBeTruthy();
        });
    });
});
