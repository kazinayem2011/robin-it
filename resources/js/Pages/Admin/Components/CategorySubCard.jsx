import React from 'react';
import { Edit2, Trash2, Plus } from 'lucide-react';
import { CategoryChip } from './CategoryChip';
import { getCategoryIcon } from '@/utils/iconMap';

/**
 * Reusable Level 2 Subcategory Card
 */
export const CategorySubCard = ({ sub, onEdit, onDelete, onAddChild }) => {
    return (
        <div className="admin-cat-tree-sub-card">
            <div className="admin-cat-tree-sub-header">
                <div className="admin-cat-tree-sub-title">
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
                        onClick={() => onEdit(sub)}
                        title="Edit Subcategory"
                    >
                        <Edit2 size={12} />
                    </button>
                    <button
                        type="button"
                        className="admin-sub-icon-btn btn-danger"
                        onClick={() => onDelete(sub)}
                        title="Delete Subcategory"
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
