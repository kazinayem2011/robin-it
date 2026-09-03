import { describe, it, expect, vi, afterEach } from 'vitest';
import { offerWindow, timeLeft } from '../offerWindow';

/**
 * How an offer's window reads.
 *
 * `status` is worked out on the server against its own clock, so the browser
 * never decides what is running by comparing dates in whatever timezone the
 * visitor's machine is set to. This turns that plus the dates into a line and
 * a badge.
 */

const DAY = 24 * 60 * 60 * 1000;
const at = (ms) => new Date(Date.now() + ms).toISOString();

afterEach(() => vi.useRealTimers());

describe('offerWindow', () => {
    it('reads a window as a range', () => {
        const { range } = offerWindow({
            status: 'running',
            starts_at: '2026-09-01T00:00:00Z',
            ends_at: '2026-09-30T00:00:00Z',
        });

        // "Sept" or "Sep" depending on the ICU build — the point is the month
        // and year are there, not which abbreviation this Node ships.
        expect(range).toMatch(/Sept? 2026/);
        expect(range).toContain('–');
    });

    it('says what it can when only one end is known', () => {
        expect(
            offerWindow({ status: 'running', ends_at: '2026-09-30T00:00:00Z' })
                .range,
        ).toMatch(/^Until /);
        expect(
            offerWindow({
                status: 'running',
                starts_at: '2026-09-01T00:00:00Z',
            }).range,
        ).toMatch(/^From /);
    });

    /* An offer with no window is a standing one, not a broken one. */
    it('calls an offer with no dates always on', () => {
        expect(offerWindow({ status: 'running' }).range).toBe('Always on');
    });

    describe('the badge', () => {
        /* "Ends 30 Sep" next to "01 Sep – 30 Sep" is the same fact twice. */
        it('is absent when the dates already say it', () => {
            expect(
                offerWindow({ status: 'running', ends_at: at(20 * DAY) }).badge,
            ).toBeNull();
        });

        it('warns when the end is close', () => {
            expect(
                offerWindow({ status: 'running', ends_at: at(2 * DAY) }),
            ).toMatchObject({
                badge: '2 days left',
                tone: 'urgent',
            });
        });

        it('says last day on the last day', () => {
            expect(
                offerWindow({ status: 'running', ends_at: at(3600000) }).badge,
            ).toBe('Last day');
        });

        it('marks one that has not begun', () => {
            const { badge, tone } = offerWindow({
                status: 'upcoming',
                starts_at: '2026-12-01T00:00:00Z',
            });

            expect(badge).toMatch(/^Starts /);
            expect(tone).toBe('upcoming');
        });

        it('marks one that has finished', () => {
            expect(
                offerWindow({ status: 'ended', ends_at: at(-DAY) }),
            ).toMatchObject({
                badge: 'Ended',
                tone: 'ended',
            });
        });
    });
});

describe('timeLeft', () => {
    it('breaks the wait into whole units', () => {
        const now = Date.UTC(2026, 8, 1, 0, 0, 0);
        const end = new Date(Date.UTC(2026, 8, 3, 5, 30, 15));

        expect(timeLeft(end, now)).toEqual({
            days: 2,
            hours: 5,
            minutes: 30,
            seconds: 15,
        });
    });

    /* null, so the page can stop the timer rather than render zeroes for the
       rest of the visit. */
    it('is null once there is nothing left to count', () => {
        expect(timeLeft(new Date(Date.now() - 1000))).toBeNull();
        expect(timeLeft(null)).toBeNull();
        expect(timeLeft('not a date')).toBeNull();
    });
});
