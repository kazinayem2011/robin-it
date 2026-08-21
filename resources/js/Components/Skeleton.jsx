import React from 'react';

/**
 * Reusable Skeleton Shimmer Loading Placeholder
 */
export const Skeleton = ({
    width = '100%',
    height = '20px',
    borderRadius = 'var(--radius-xs, 8px)',
    className = '',
    style = {},
}) => {
    return (
        <div
            className={`skeleton-shimmer ${className}`.trim()}
            style={{
                width,
                height,
                borderRadius,
                background:
                    'linear-gradient(90deg, var(--gray-200) 25%, var(--gray-100) 50%, var(--gray-200) 75%)',
                backgroundSize: '200% 100%',
                animation: 'shimmer 1.5s infinite',
                ...style,
            }}
        />
    );
};

export const ProductCardSkeleton = () => {
    return (
        <div className="product-card-skeleton">
            <Skeleton height="180px" borderRadius="var(--radius-sm, 8px)" />
            <Skeleton width="40%" height="14px" />
            <Skeleton width="90%" height="18px" />
            <Skeleton width="60%" height="14px" />
            <div className="skeleton-footer-row">
                <Skeleton width="50%" height="22px" />
                <Skeleton
                    width="36px"
                    height="36px"
                    borderRadius="var(--radius-xs, 8px)"
                />
            </div>
        </div>
    );
};

export default Skeleton;
