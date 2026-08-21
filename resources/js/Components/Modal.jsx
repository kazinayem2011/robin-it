import React, { useEffect } from 'react';
import { X } from 'lucide-react';

/**
 * Reusable Vanilla CSS Modal Component (DRY & SSOT).
 */
export const Modal = ({
    isOpen = false,
    onClose,
    title = '',
    children,
    footer,
    maxWidth = '600px',
}) => {
    // ESC key listener & body scroll lock
    useEffect(() => {
        if (!isOpen) return;

        const handleKeyDown = (e) => {
            if (e.key === 'Escape' && onClose) {
                onClose();
            }
        };

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = 'unset';
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [isOpen, onClose]);

    if (!isOpen) return null;

    return (
        <div className="modal-backdrop-overlay" onClick={onClose}>
            <div
                className="modal-dialog-container"
                onClick={(e) => e.stopPropagation()}
                style={{ maxWidth: maxWidth }}
            >
                {/* Modal Header */}
                <div className="modal-header-bar">
                    <h3 className="modal-title-text">{title}</h3>
                    {onClose && (
                        <button
                            type="button"
                            onClick={onClose}
                            className="modal-close-btn"
                            title="Close modal"
                        >
                            <X size={18} />
                        </button>
                    )}
                </div>

                {/* Modal Body */}
                <div className="modal-body-content">{children}</div>

                {/* Optional Footer */}
                {footer && <div className="modal-footer-bar">{footer}</div>}
            </div>
        </div>
    );
};

export default Modal;
