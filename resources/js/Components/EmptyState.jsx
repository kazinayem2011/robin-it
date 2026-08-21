import React from 'react';
import { PackageOpen } from 'lucide-react';
import Button from './Button';

/**
 * Reusable EmptyState Component
 */
export const EmptyState = ({
    icon: Icon = PackageOpen,
    title = 'No items found',
    description = 'There is currently no data to display here.',
    actionLabel = '',
    onAction = null,
    actionHref = '',
    className = '',
}) => {
    return (
        <div className={`empty-state-wrapper ${className}`.trim()}>
            <div className="empty-state-icon-box">
                <Icon size={30} />
            </div>

            <h3 className="empty-state-title">{title}</h3>

            <p
                className={`empty-state-desc ${actionLabel ? 'has-action' : ''}`}
            >
                {description}
            </p>

            {actionLabel && actionHref && (
                <a href={actionHref} className="btn btn-primary btn-sm">
                    {actionLabel}
                </a>
            )}

            {actionLabel && onAction && !actionHref && (
                <Button variant="primary" size="sm" onClick={onAction}>
                    {actionLabel}
                </Button>
            )}
        </div>
    );
};

export default EmptyState;
