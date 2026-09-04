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
});
