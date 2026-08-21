import React from 'react';
import { Plus, Edit2, Trash2, ChevronDown, ChevronRight } from 'lucide-react';
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
}) => {
    return (
        <div className="admin-cat-tree-parent-card">
            {/* Level 1: Root Category Header */}
            <div className="admin-cat-tree-parent-header">
                <div className="admin-cat-tree-parent-left">
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

                            {Boolean(parent.is_offer) ? (
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
                        parent.children.map((sub) => (
                            <CategorySubCard
                                key={sub.id}
                                sub={sub}
                                onEdit={onEdit}
                                onDelete={onDelete}
                                onAddChild={onAddChild}
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
