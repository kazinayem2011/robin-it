import React from 'react';
import {
    Plus,
    Edit2,
    Trash2,
    ChevronDown,
    ChevronRight,
    ArrowUp,
    ArrowDown,
    GripVertical,
} from 'lucide-react';
import { CategorySubCard } from './CategorySubCard';
import { getCategoryIcon } from '@/utils/iconMap';

/**
 * Reusable Level 1 Root Category Card
 */
export const CategoryParentCard = ({
    parent,
    isCollapsed,
    onToggleCollapse,
    onEdit,
    onDelete,
    onAddSubcategory,
    onAddChild,
    /*
     * Where this sits among its siblings, and how to move it.
     *
     * Passed in rather than worked out here: only the list knows how many
     * there are, and an arrow that is live at the end of the list moves
     * nothing and looks broken doing it.
     */
    onMove,
    isFirst = false,
    isLast = false,
    /*
     * Dragging. The arrows stay: they are the keyboard and touch route, and a
     * drag is a mouse gesture that neither of those can perform.
     */
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
            className={`admin-cat-tree-parent-card${
                draggingId === parent.id ? ' is-dragging' : ''
            }`}
            draggable={isDraggable}
            onDragStart={(event) => {
                event.stopPropagation();
                event.dataTransfer.effectAllowed = 'move';
                onDragStart?.(parent, null);
            }}
            /* null is the roots' shelf: these cards have no parent. */
            onDragEnter={() => onDragEnterRow?.(null, index)}
            onDragOver={(event) => event.preventDefault()}
            onDrop={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onDrop?.();
            }}
            onDragEnd={() => onDragEnd?.()}
        >
            {/* Level 1: Root Category Header */}
            <div className="admin-cat-tree-parent-header">
                <div className="admin-cat-tree-parent-left">
                    {isDraggable ? (
                        <span
                            className="admin-cat-drag-handle"
                            title="Drag to reorder"
                            aria-hidden="true"
                        >
                            <GripVertical size={15} />
                        </span>
                    ) : null}

                    <button
                        type="button"
                        className="admin-cat-collapse-btn"
                        onClick={() => onToggleCollapse(parent.id)}
                        title={
                            isCollapsed
                                ? 'Expand subcategories'
                                : 'Collapse subcategories'
                        }
                    >
                        {isCollapsed ? (
                            <ChevronRight size={15} />
                        ) : (
                            <ChevronDown size={15} />
                        )}
                    </button>

                    <div className="admin-cat-tree-parent-icon">
                        {getCategoryIcon(parent, { size: 18 })}
                    </div>

                    <div className="admin-cat-title-group">
                        <div className="admin-cat-title-row">
                            <strong className="admin-cat-tree-parent-title">
                                {parent.name}
                            </strong>

                            {parent.badge ? (
                                <span
                                    className={`nav-chip-badge badge-${parent.badge.toLowerCase()}`}
                                >
                                    {parent.badge}
                                </span>
                            ) : null}

                            {parent.is_offer ? (
                                <span className="nav-chip-badge badge-sale">
                                    OFFER
                                </span>
                            ) : null}

                            {!parent.is_active || parent.is_active === 0 ? (
                                <span className="admin-cat-inactive-pill">
                                    Inactive
                                </span>
                            ) : null}
                        </div>

                        <span className="admin-cat-tree-parent-slug">
                            slug: /shop/{parent.slug}
                        </span>
                    </div>
                </div>

                <div className="admin-cat-tree-parent-right">
                    <span className="admin-cat-tree-parent-count">
                        {parent.children?.length || 0} Subcategories
                    </span>

                    <button
                        type="button"
                        className="admin-cat-action-btn btn-primary-sm"
                        onClick={() => onAddSubcategory(parent, 2)}
                        title="Add Level 2 Subcategory"
                    >
                        <Plus size={13} /> Add Subcategory
                    </button>

                    {/* Order is what the shop shows: the menu, the footer
                        and every picker read these in this order. Both are
                        dead while the tree is filtered — the page withholds
                        onMove then, and a live arrow that moves nothing
                        looks broken doing it. */}
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        disabled={!onMove || isFirst}
                        onClick={() => onMove?.(parent, 'up')}
                        title="Move up"
                        aria-label={`Move ${parent.name} up`}
                    >
                        <ArrowUp size={14} />
                    </button>

                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        disabled={!onMove || isLast}
                        onClick={() => onMove?.(parent, 'down')}
                        title="Move down"
                        aria-label={`Move ${parent.name} down`}
                    >
                        <ArrowDown size={14} />
                    </button>

                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        onClick={() => onEdit(parent)}
                        title="Edit Category"
                    >
                        <Edit2 size={14} />
                    </button>

                    <button
                        type="button"
                        className="admin-table-icon-btn btn-danger"
                        onClick={() => onDelete(parent)}
                        title="Delete Category"
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            </div>

            {/* Level 2 & 3 Subcategories Grid */}
            {!isCollapsed && (
                <div className="admin-cat-tree-sub-grid">
                    {parent.children && parent.children.length > 0 ? (
                        parent.children.map((sub, subIndex) => (
                            <CategorySubCard
                                key={sub.id}
                                sub={sub}
                                onEdit={onEdit}
                                onDelete={onDelete}
                                onAddChild={onAddChild}
                                onMove={onMove}
                                isFirst={!onMove || subIndex === 0}
                                isLast={
                                    !onMove ||
                                    subIndex === parent.children.length - 1
                                }
                                parentId={parent.id}
                                index={subIndex}
                                draggingId={draggingId}
                                onDragStart={onDragStart}
                                onDragEnterRow={onDragEnterRow}
                                onDrop={onDrop}
                                onDragEnd={onDragEnd}
                            />
                        ))
                    ) : (
                        <div className="admin-empty-sub-notice">
                            No subcategories yet. Click{' '}
                            <strong>+ Add Subcategory</strong> above to
                            populate.
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default CategoryParentCard;
