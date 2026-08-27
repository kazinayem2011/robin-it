import React from 'react';
import { Info } from 'lucide-react';
import Button from '@/Components/Button';
import Modal from '@/Components/Modal';

/**
 * Reusable Category Delete Confirmation Modal
 */
export const CategoryDeleteModal = ({
    deleteModalState,
    onClose,
    onConfirmDelete,
    isSubmitting = false,
}) => {
    return (
        <Modal
            isOpen={deleteModalState.isOpen}
            onClose={onClose}
            title="Confirm Category Deletion"
            maxWidth="460px"
        >
            <div className="admin-modal-body-pad">
                <p className="admin-confirm-text">
                    Are you sure you want to delete{' '}
                    <strong>'{deleteModalState.category?.name}'</strong>?
                </p>
                <div className="admin-delete-warning-box">
                    <Info size={16} className="warning-icon" />
                    <span>
                        Warning: Deleting a category will also delete its nested
                        subcategories.
                    </span>
                </div>
            </div>

            <div className="admin-modal-footer-btns">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={isSubmitting}
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    variant="danger"
                    loading={isSubmitting}
                    onClick={onConfirmDelete}
                >
                    Yes, Delete Category
                </Button>
            </div>
        </Modal>
    );
};

export default CategoryDeleteModal;
