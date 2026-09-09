import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { CategoryParentCard } from '../CategoryParentCard';

/**
 * Rearranging the shop by dragging a card.
 *
 * The cards nest — a subcategory card sits inside the root card it belongs to
 * — and drag events bubble, so the thing worth pinning down is which shelf a
 * given card reports itself on.
 */
describe('category drag', () => {
    const parent = {
        id: 1,
        name: 'Component',
        slug: 'component',
        is_active: true,
        children: [
            { id: 10, name: 'Processor', slug: 'processor', children: [] },
            { id: 11, name: 'Casing', slug: 'casing', children: [] },
        ],
    };

    const noop = () => {};

    const draw = (props = {}) => {
        const handlers = {
            onDragStart: vi.fn(),
            onDragEnterRow: vi.fn(),
            onDrop: vi.fn(),
            onDragEnd: vi.fn(),
        };

        render(
            <CategoryParentCard
                parent={parent}
                isCollapsed={false}
                onToggleCollapse={noop}
                onEdit={noop}
                onDelete={noop}
                onAddSubcategory={noop}
                onAddChild={noop}
                onMove={noop}
                index={3}
                {...handlers}
                {...props}
            />,
        );

        return handlers;
    };

    /* dataTransfer is not implemented in jsdom, and the handler writes to it. */
    const dragStart = (node) =>
        fireEvent.dragStart(node, { dataTransfer: { effectAllowed: '' } });

    const cardFor = (name) =>
        screen.getByText(name).closest('[draggable="true"]');

    it('reports a root card on the root shelf', () => {
        const { onDragStart } = draw();

        dragStart(cardFor('Component'));

        expect(onDragStart).toHaveBeenCalledTimes(1);
        expect(onDragStart).toHaveBeenCalledWith(parent, null);
    });

    /**
     * The one that matters. Without stopPropagation the event reaches the root
     * card too, which would start a second drag and carry the whole shelf
     * instead of the one subcategory that was picked up.
     */
    it('reports a subcategory on its own parent’s shelf, once', () => {
        const { onDragStart } = draw();

        dragStart(cardFor('Casing'));

        expect(onDragStart).toHaveBeenCalledTimes(1);
        expect(onDragStart).toHaveBeenCalledWith(parent.children[1], parent.id);
    });

    it('does not let a subcategory’s drop reach the root card', () => {
        const { onDrop } = draw();

        fireEvent.drop(cardFor('Processor'));

        expect(onDrop).toHaveBeenCalledTimes(1);
    });

    it('tells the page which row is being crossed', () => {
        const { onDragEnterRow } = draw();

        fireEvent.dragEnter(cardFor('Casing'));
        expect(onDragEnterRow).toHaveBeenLastCalledWith(parent.id, 1);

        fireEvent.dragEnter(cardFor('Component'));
        expect(onDragEnterRow).toHaveBeenLastCalledWith(null, 3);
    });

    /*
     * Reordering is off while the tree is filtered, and the page says so by
     * withholding the handler. Nothing should then look draggable.
     */
    it('is not draggable at all when reordering is off', () => {
        draw({ onDragStart: null });

        expect(screen.queryByTitle('Drag to reorder')).toBeNull();
        expect(document.querySelectorAll('[draggable="true"]')).toHaveLength(0);
    });

    it('fades only the card being carried', () => {
        draw({ draggingId: 11 });

        expect(cardFor('Casing').className).toContain('is-dragging');
        expect(cardFor('Processor').className).not.toContain('is-dragging');
        expect(cardFor('Component').className).not.toContain('is-dragging');
    });
});
