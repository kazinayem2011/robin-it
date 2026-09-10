import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import ConfirmDialog from '../ConfirmDialog';

/**
 * The one dialog that stands between a long description and losing it.
 */
describe('ConfirmDialog', () => {
    const draw = (props = {}) => {
        const onConfirm = vi.fn();
        const onCancel = vi.fn();

        render(
            <ConfirmDialog
                isOpen
                title="Discard this product?"
                message="What you have typed here has not been saved."
                confirmLabel="Discard"
                cancelLabel="Keep editing"
                onConfirm={onConfirm}
                onCancel={onCancel}
                {...props}
            />,
        );

        return { onConfirm, onCancel };
    };

    it('says what is at stake', () => {
        draw();

        expect(screen.getByText('Discard this product?')).toBeTruthy();
        expect(screen.getByText(/has not been saved/)).toBeTruthy();
    });

    it('carries the answer through', async () => {
        const user = userEvent.setup();
        const { onConfirm, onCancel } = draw();

        await user.click(screen.getByRole('button', { name: 'Discard' }));
        expect(onConfirm).toHaveBeenCalledTimes(1);
        expect(onCancel).not.toHaveBeenCalled();
    });

    it('carries the safe answer through too', async () => {
        const user = userEvent.setup();
        const { onConfirm, onCancel } = draw();

        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(onConfirm).not.toHaveBeenCalled();
    });

    /*
     * Carrying on is the safe answer, so it comes first — a reader tabbing in
     * and pressing space keeps their work rather than losing it.
     */
    it('puts the safe answer first', () => {
        draw();

        const buttons = screen
            .getAllByRole('button')
            .map((b) => b.textContent.trim());
        const cancel = buttons.indexOf('Keep editing');
        const confirm = buttons.indexOf('Discard');

        expect(cancel).toBeGreaterThan(-1);
        expect(cancel).toBeLessThan(confirm);
    });

    it('draws nothing when it is not open', () => {
        const { container } = render(
            <ConfirmDialog
                isOpen={false}
                message="x"
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
            />,
        );

        expect(container.textContent).toBe('');
    });
});
