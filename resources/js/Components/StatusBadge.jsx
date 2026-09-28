import React from 'react';
import {
    Clock,
    CheckCircle2,
    Truck,
    RefreshCw,
    XCircle,
    Undo2,
} from 'lucide-react';

/**
 * Reusable StatusBadge component (SSOT & DRY).
 */
export const StatusBadge = ({
    status = 'pending',
    className = '',
    style = {},
}) => {
    const s = String(status).toLowerCase();

    const config = {
        pending: {
            label: 'Pending',
            icon: Clock,
        },
        processing: {
            label: 'Processing',
            icon: RefreshCw,
        },
        shipped: {
            label: 'Shipped',
            icon: Truck,
        },
        delivered: {
            label: 'Delivered',
            icon: CheckCircle2,
        },
        cancelled: {
            label: 'Cancelled',
            icon: XCircle,
        },
        // Came back after delivery. It fell through to "Pending", so a
        // finished order looked like one still to be sent.
        returned: {
            label: 'Returned',
            icon: Undo2,
        },
    };

    const current = config[s] || config.pending;
    const Icon = current.icon;

    return (
        <span
            className={`status-pill status-${s} ${className}`.trim()}
            style={style}
        >
            <Icon size={13} />
            {current.label}
        </span>
    );
};

export default StatusBadge;
