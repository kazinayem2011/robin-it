import React from 'react';
import {
    CheckCircle2,
    AlertCircle,
    AlertTriangle,
    Info,
    X,
} from 'lucide-react';
import useAppStore from '../store/useAppStore';

const TOAST_ICONS = {
    success: CheckCircle2,
    error: AlertCircle,
    warning: AlertTriangle,
    info: Info,
};

export const Toast = ({ toast }) => {
    const removeToast = useAppStore((state) => state.removeToast);
    const Icon = TOAST_ICONS[toast.type] || Info;

    return (
        <div
            className={`toast-item toast-${toast.type}`}
            /*
             * An error interrupts; everything else waits its turn. assertive
             * on a success message would talk over whatever a screen reader
             * was in the middle of saying.
             */
            role={toast.type === 'error' ? 'alert' : 'status'}
            aria-live={toast.type === 'error' ? 'assertive' : 'polite'}
        >
            <div className="toast-icon-box">
                <Icon size={18} />
            </div>

            <div className="toast-content">
                {toast.title && (
                    <strong className="toast-title">{toast.title}</strong>
                )}
                <p className="toast-message">{toast.message}</p>
            </div>

            <button
                type="button"
                onClick={() => removeToast(toast.id)}
                className="toast-close-btn"
                aria-label="Close notification"
            >
                <X size={14} />
            </button>
        </div>
    );
};

export const ToastContainer = () => {
    const toasts = useAppStore((state) => state.toasts);

    if (toasts.length === 0) return null;

    return (
        <div className="toast-container">
            {toasts.map((toast) => (
                <Toast key={toast.id} toast={toast} />
            ))}
        </div>
    );
};

/*
 * A title only when it adds something.
 *
 * These defaulted to "Success", "Error", "Warning" and "Notice", so the
 * commonest notice in the app read "Success" above "Site settings saved
 * successfully" — the same word twice, in bold, above the sentence that
 * already said it. The colour and the icon carry the severity; the heading
 * was repeating what they had already said.
 *
 * The parameter stays, because some callers pass a title that genuinely tells
 * you something the message does not — "Incompatible Build" above the reason,
 * "Subscribed" above the confirmation.
 */
export const toast = {
    success: (message, title = null, duration = 4000) =>
        useAppStore
            .getState()
            .addToast({ type: 'success', title, message, duration }),
    error: (message, title = null, duration = 5000) =>
        useAppStore
            .getState()
            .addToast({ type: 'error', title, message, duration }),
    warning: (message, title = null, duration = 4500) =>
        useAppStore
            .getState()
            .addToast({ type: 'warning', title, message, duration }),
    info: (message, title = null, duration = 4000) =>
        useAppStore
            .getState()
            .addToast({ type: 'info', title, message, duration }),
};

export default ToastContainer;
