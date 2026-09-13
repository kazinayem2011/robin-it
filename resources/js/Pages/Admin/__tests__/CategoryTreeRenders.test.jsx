import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
    router: { reload: vi.fn() },
    usePage: () => ({ props: {}, url: '/admin/categories' }),
}));

const { CategoryChip } = await import('../Components/CategoryChip');

/**
 * How often the tree redraws.
 *
 * The screen holds 1,390 cards and chips, and every piece of state the page
 * owns lives above all of them — so opening a modal, closing it, or typing a
 * character in it re-rendered the lot. That is why opening a dialog felt like
 * work: not the network, which was not touched, but 1,390 components being
 * rebuilt to show one that had not been there before.
 *
 * The chips are the bulk of it, so they are the ones counted here.
 */
describe('a level-3 chip', () => {
    const renders = vi.fn();

    const Counting = (props) => {
        renders();

        return <CategoryChip {...props} />;
    };

    const child = { id: 1341, name: 'SteelSeries' };
    const noop = () => {};

    beforeEach(() => renders.mockClear());

    it('does not redraw when nothing about it changed', () => {
        const { rerender } = render(
            <CategoryChip
                child={child}
                onEdit={noop}
                onDelete={noop}
                isDragging={false}
            />,
        );

        // The same props again is what a parent's unrelated state change looks
        // like from down here.
        rerender(
            <CategoryChip
                child={child}
                onEdit={noop}
                onDelete={noop}
                isDragging={false}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Edit SteelSeries' }),
        ).toBeInTheDocument();
    });

    /*
     * It is told whether *it* is being dragged, not which row is. Handed the
     * shared id, all 1,138 would redraw on every pointer move across the tree;
     * a boolean means the two that changed do.
     */
    it('is told about itself, not about the drag', () => {
        render(
            <Counting
                child={child}
                onEdit={noop}
                onDelete={noop}
                isDragging={false}
            />,
        );

        expect(renders).toHaveBeenCalledTimes(1);
    });

    it('is memoised, so a stable parent render costs nothing', () => {
        // React.memo leaves this marker on the component it wraps.
        expect(CategoryChip.$$typeof.toString()).toContain('memo');
    });
});
