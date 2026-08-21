import React from 'react';

/**
 * Reusable Card Component (Vanilla CSS Design System)
 */
export const Card = ({
    children,
    className = '',
    header = null,
    footer = null,
    hoverable = false,
    ...props
}) => {
    return (
        <div
            {...props}
            className={`card ${hoverable ? 'card-hover' : ''} ${className}`.trim()}
        >
            {header && <div className="card-header">{header}</div>}
            <div className="card-body">{children}</div>
            {footer && <div className="card-footer">{footer}</div>}
        </div>
    );
};

export default Card;
