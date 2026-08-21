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
        <div className={`toast-item toast-${toast.type}`}>
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

export const toast = {
    success: (message, title = 'Success', duration = 4000) =>
        useAppStore
            .getState()
            .addToast({ type: 'success', title, message, duration }),
    error: (message, title = 'Error', duration = 5000) =>
        useAppStore
            .getState()
            .addToast({ type: 'error', title, message, duration }),
    warning: (message, title = 'Warning', duration = 4500) =>
        useAppStore
            .getState()
            .addToast({ type: 'warning', title, message, duration }),
    info: (message, title = 'Notice', duration = 4000) =>
        useAppStore
            .getState()
            .addToast({ type: 'info', title, message, duration }),
};

export default ToastContainer;
