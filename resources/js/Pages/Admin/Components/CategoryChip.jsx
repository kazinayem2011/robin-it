import React from 'react';
import { XCircle } from 'lucide-react';

/**
 * Reusable Level 3 Series Chip
 */
export const CategoryChip = ({ child, onEdit, onDelete }) => {
    return (
        <span className="admin-cat-tree-l3-chip">
            <span
                className="l3-chip-label"
                onClick={() => onEdit(child)}
                title="Click to edit item"
            >
                {child.name}
            </span>
            <button
                type="button"
                className="l3-chip-remove-btn"
                onClick={() => onDelete(child)}
                title="Delete item"
            >
                <XCircle size={12} />
            </button>
        </span>
    );
};

export default CategoryChip;
