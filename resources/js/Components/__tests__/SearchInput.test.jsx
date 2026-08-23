import React, { useState } from 'react';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import SearchInput from '../SearchInput';

/**
 * SearchInput used to bring the admin down.
 *
 * The debounce effect depended on `onSearch`, and every caller passes an inline
 * arrow, so the callback was a new identity on each render: effect fires →
 * request → props update → re-render → new identity → effect fires. /admin/customers
 * was measured at 280 requests in 6 seconds.
 *
 * These pin both halves of the fix — no loop, and search still works — because
 * the cheap way to stop the loop is to break searching.
 */
describe('SearchInput', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    const flushDebounce = async (ms = 600) => {
        await act(async () => {
            vi.advanceTimersByTime(ms);
        });
    };

    it('does not fire on mount', async () => {
        const onSearch = vi.fn();

        render(<SearchInput value="" onSearch={onSearch} />);
        await flushDebounce();

        // The server already rendered this page with its data; firing here
        // would re-request it for nothing.
        expect(onSearch).not.toHaveBeenCalled();
    });

    /** The regression itself: a caller that re-renders must not re-trigger. */
    it('does not loop when the parent re-renders with a new callback identity', async () => {
        const spy = vi.fn();

        // Mirrors how every admin page uses it: an inline arrow, recreated on
        // every render, and a parent that re-renders when results come back.
        //
        // The re-render is capped. Without a cap the old code loops forever and
        // the test hangs instead of failing, which in CI reads as a timeout
        // rather than as this bug.
        const RENDER_CAP = 25;

        function Page() {
            const [renders, setRenders] = useState(0);

            return (
                <>
                    <span data-testid="renders">{renders}</span>
                    <SearchInput
                        value=""
                        onSearch={(term) => {
                            spy(term);
                            // Stand-in for props arriving from the server.
                            setRenders((n) => Math.min(n + 1, RENDER_CAP));
                        }}
                    />
                </>
            );
        }

        render(<Page />);
        await flushDebounce();

        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        await user.type(screen.getByRole('textbox'), 'Kazi');
        await flushDebounce();

        expect(spy).toHaveBeenCalledTimes(1);
        expect(spy).toHaveBeenLastCalledWith('Kazi');

        // Let plenty of debounce windows pass; a loop would keep going.
        await flushDebounce(3000);
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it('still searches, and reports what was typed', async () => {
        const onSearch = vi.fn();

        render(<SearchInput value="" onSearch={onSearch} />);
        await flushDebounce();

        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        await user.type(screen.getByRole('textbox'), 'Ryzen');
        await flushDebounce();

        expect(onSearch).toHaveBeenCalledWith('Ryzen');
    });

    it('debounces a burst of typing into one request', async () => {
        const onSearch = vi.fn();

        render(<SearchInput value="" onSearch={onSearch} />);
        await flushDebounce();

        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        await user.type(screen.getByRole('textbox'), 'RTX 4090');
        await flushDebounce();

        expect(onSearch).toHaveBeenCalledTimes(1);
    });

    it('reports the cleared term so the caller can restore the full list', async () => {
        const onSearch = vi.fn();

        render(<SearchInput value="" onSearch={onSearch} />);
        await flushDebounce();

        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        const box = screen.getByRole('textbox');

        await user.type(box, 'Kazi');
        await flushDebounce();
        await user.clear(box);
        await flushDebounce();

        expect(onSearch).toHaveBeenLastCalledWith('');
    });

    /** A stale callback would search with the right term against the wrong handler. */
    it('uses the newest callback, not the one from first render', async () => {
        const first = vi.fn();
        const second = vi.fn();

        const { rerender } = render(<SearchInput value="" onSearch={first} />);
        await flushDebounce();

        rerender(<SearchInput value="" onSearch={second} />);

        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        await user.type(screen.getByRole('textbox'), 'x');
        await flushDebounce();

        expect(second).toHaveBeenCalledWith('x');
        expect(first).not.toHaveBeenCalled();
    });
});
