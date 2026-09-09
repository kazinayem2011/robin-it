import React from 'react';
import {
    Edit2,
    Trash2,
    Plus,
    ArrowUp,
    ArrowDown,
    GripVertical,
} from 'lucide-react';
import { CategoryChip } from './CategoryChip';
import { getCategoryIcon } from '@/utils/iconMap';

/**
 * Reusable Level 2 Subcategory Card
 */
export const CategorySubCard = ({
    sub,
    onEdit,
    onDelete,
    onAddChild,
    /* Position among its own siblings; see CategoryParentCard for why the
       list decides rather than the card. */
    onMove,
    isFirst = false,
    isLast = false,
    /* See CategoryParentCard: same drag, one shelf down. */
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
        <div
            className={`admin-cat-tree-sub-card${
                draggingId === sub.id ? ' is-dragging' : ''
            }`}
            draggable={isDraggable}
            /*
             * Stopped here, every one of them. These cards sit inside a
             * draggable parent card, so without this a subcategory picked up
             * would start its parent's drag as well and carry the whole shelf.
             */
            onDragStart={(event) => {
                event.stopPropagation();
                event.dataTransfer.effectAllowed = 'move';
                onDragStart?.(sub, parentId);
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
            <div className="admin-cat-tree-sub-header">
                <div className="admin-cat-tree-sub-title">
                    {isDraggable ? (
                        <span
                            className="admin-cat-drag-handle is-sub"
                            title="Drag to reorder"
                            aria-hidden="true"
                        >
                            <GripVertical size={13} />
                        </span>
                    ) : null}

                    <span className="admin-sub-icon-badge">
                        {getCategoryIcon(sub, { size: 14 })}
                    </span>
                    <span className="admin-sub-name">{sub.name}</span>
                    <span className="admin-cat-tree-level-tag">L2</span>
                </div>

                <div className="admin-sub-action-group">
                    <button
                        type="button"
                        className="admin-sub-icon-btn"
                        disabled={isFirst}
                        onClick={() => onMove?.(sub, 'up')}
                        title="Move up"
                        aria-label={`Move ${sub.name} up`}
                    >
                        <ArrowUp size={12} />
                    </button>
                    <button
                        type="button"
                        className="admin-sub-icon-btn"
                        disabled={isLast}
                        onClick={() => onMove?.(sub, 'down')}
                        title="Move down"
                        aria-label={`Move ${sub.name} down`}
                    >
                        <ArrowDown size={12} />
                    </button>
                    <button
                        type="button"
                        className="admin-sub-icon-btn"
                        onClick={() => onEdit(sub)}
                        title="Edit Subcategory"
                        aria-label={`Edit ${sub.name}`}
                    >
                        <Edit2 size={12} />
                    </button>
                    <button
                        type="button"
                        className="admin-sub-icon-btn btn-danger"
                        onClick={() => onDelete(sub)}
                        title="Delete Subcategory"
                        aria-label={`Delete ${sub.name}`}
                    >
                        <Trash2 size={12} />
                    </button>
                </div>
            </div>

            <span className="admin-sub-slug-tag">/{sub.slug}</span>

            {/* Level 3: Children Series & Lineups */}
            <div className="admin-cat-tree-l3-list">
                {sub.children?.map((child) => (
                    <CategoryChip
                        key={child.id}
                        child={child}
                        onEdit={onEdit}
                        onDelete={onDelete}
                    />
                ))}

                <button
                    type="button"
                    className="admin-cat-tree-l3-add-chip"
                    onClick={() => onAddChild(sub, 3)}
                >
                    <Plus size={11} /> Add Item/Series
                </button>
            </div>
        </div>
    );
};

export default CategorySubCard;
