import React, { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import { SerialsGone, serialsToName } from '../SerialsMoving';

/**
 * Units taken off a shelf take their serials with them: a count or a
 * correction asks which. It used to change the number alone, leaving the
 * serials "On the shelf" for units that were gone.
 */
describe('naming the serials that left a shelf', () => {
    const shelf = [
        { id: 1, serial: 'PX-1' },
        { id: 2, serial: 'PX-2' },
        { id: 3, serial: 'PX-3' },
    ];

    it('asks for as many as the serials outnumber what is left', () => {
        expect(serialsToName(shelf, 2)).toBe(1);
        expect(serialsToName(shelf, 0)).toBe(3);
        // Units without serials can go first: nothing to name.
        expect(serialsToName(shelf.slice(0, 1), 1)).toBe(0);
    });

    it('ticks and counts them, and says what becomes of them', async () => {
        function Form() {
            const [chosen, setChosen] = useState([]);
            return (
                <SerialsGone
                    serials={shelf}
                    needed={1}
                    removed={1}
                    chosen={chosen}
                    onChange={setChosen}
                />
            );
        }
        const user = userEvent.setup();
        render(<Form />);

        expect(
            screen.getByText('Which serial number is no longer on the shelf?'),
        ).toBeInTheDocument();
        expect(screen.getByText(/0 of 1 ticked/)).toBeInTheDocument();
        expect(
            screen.getByText(/kept on record as Missing/),
        ).toBeInTheDocument();

        await user.click(screen.getByLabelText('PX-2'));
        expect(screen.getByText(/1 of 1 ticked/)).toBeInTheDocument();
    });

    it('says damaged units are written off', () => {
        render(
            <SerialsGone
                serials={shelf}
                needed={1}
                removed={1}
                chosen={[]}
                onChange={() => {}}
                writtenOff
            />,
        );

        expect(screen.getByText(/They are written off/)).toBeInTheDocument();
    });
});
