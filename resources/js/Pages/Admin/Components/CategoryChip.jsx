import React from 'react';
import { Edit2, XCircle } from 'lucide-react';

/**
 * A level-3 shelf in the tree — usually a maker, like Asus under Gaming Laptop.
 *
 * It is drawn small because there are dozens under a single parent, but small
 * cost it the one thing it needed to say: that it opens. The name was a span
 * with an onClick and a title, beside a delete button — so the only visible
 * affordance on the chip was the destructive one, the label announced itself
 * to a keyboard as text, and the way to edit a shelf was a tooltip nobody
 * waits for. Somebody looking for "edit SteelSeries" reasonably edited the
 * card it sits in instead, and set the Headphone shelf to stand for a brand.
 *
 * The label is a button now, reachable by tab and named for what it does, with
 * a pencil that appears on hover or focus — quiet at rest, because twenty
 * permanent pencils in a row is its own kind of noise.
 */
export const CategoryChip = ({
    child,
    onEdit,
    onDelete,
    /*
     * The same drag the cards above use, one level further down. A shelf
     * commonly holds a dozen makers and the order is somebody's decision —
     * it just had no way to be made until now.
     */
    parentId = null,
    index = 0,
    draggingId = null,
    onDragStart,
    onDragEnterRow,
    onDrop,
    onDragEnd,
}) => {
    const isDraggable = Boolean(onDragStart);

    return (
        <span
            className={`admin-cat-tree-l3-chip${
                draggingId === child.id ? ' is-dragging' : ''
            }`}
            draggable={isDraggable}
            /*
             * Stopped, like the sub-card's. A chip sits inside two draggable
             * cards, so without this picking one up starts its shelf's drag
             * as well and carries the lot.
             */
            onDragStart={(event) => {
                event.stopPropagation();
                event.dataTransfer.effectAllowed = 'move';
                onDragStart?.(child, parentId);
            }}
            onDragEnter={(event) => {
                event.stopPropagation();
                onDragEnterRow?.(parentId, index);
            }}
            onDragOver={(event) => event.preventDefault()}
            onDrop={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onDrop?.();
            }}
            onDragEnd={(event) => {
                event.stopPropagation();
                onDragEnd?.();
            }}
        >
            <button
                type="button"
                className="l3-chip-label"
                onClick={() => onEdit(child)}
                title={`Edit ${child.name}`}
                aria-label={`Edit ${child.name}`}
            >
                {child.name}
                <Edit2
                    size={10}
                    className="l3-chip-pencil"
                    aria-hidden="true"
                />
            </button>
            <button
                type="button"
                className="l3-chip-remove-btn"
                onClick={() => onDelete(child)}
                title={`Delete ${child.name}`}
                aria-label={`Delete ${child.name}`}
            >
                <XCircle size={12} />
            </button>
        </span>
    );
};

export default CategoryChip;
