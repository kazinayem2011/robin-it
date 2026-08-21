import React from 'react';
import { Clock, CheckCircle2, Truck, RefreshCw, XCircle } from 'lucide-react';

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
