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
                {/*
                    Says what actually happens, both halves of it. The old
                    wording warned only about subcategories, which left an
                    admin expecting products to be destroyed too — and then
                    facing a refusal with no explanation. Products are the one
                    thing this will not take: the delete stops instead.
                */}
                <div className="admin-delete-warning-box">
                    <Info size={16} className="warning-icon" />
                    <span>
                        Any subcategories beneath it are deleted with it. If
                        products are filed here, nothing is deleted — you will
                        be told how many to move first.
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
