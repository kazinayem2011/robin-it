import React from 'react';
import Button from './Button';
import Modal from './Modal';

/**
 * "Are you sure" for the one case where the answer is expensive.
 *
 * Built on the same Modal as everything else rather than window.confirm: the
 * shop draws its own select boxes because the native one cannot be styled, and
 * a browser dialog in the middle of that is jarring enough to read as an
 * error.
 */
export const ConfirmDialog = ({
    isOpen = false,
    title = 'Are you sure?',
    message,
    confirmLabel = 'Yes',
    cancelLabel = 'Cancel',
    variant = 'danger',
    onConfirm,
    onCancel,
}) => (
    <Modal isOpen={isOpen} onClose={onCancel} title={title} maxWidth="420px">
        <div className="admin-modal-body-pad">
            <p className="admin-confirm-text">{message}</p>
        </div>

        <div className="admin-modal-footer-btns">
            {/*
                Carrying on is the safe answer, so it is the plain button and
                the one focus lands on first.
            */}
            <Button type="button" variant="outline" onClick={onCancel}>
                {cancelLabel}
            </Button>
            <Button type="button" variant={variant} onClick={onConfirm}>
                {confirmLabel}
            </Button>
        </div>
    </Modal>
);

export default ConfirmDialog;
