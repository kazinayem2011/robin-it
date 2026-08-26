import React from 'react';

/**
 * Shimmer placeholders.
 *
 * A skeleton is worth using over a spinner when it can stand in for the shape
 * that is coming, so each of these mirrors the markup of the thing it replaces
 * — same container class, same column count, same number of rows. Where that
 * shape is not knowable, a spinner is still the honest choice.
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
            /*
             * Only the per-instance dimensions are inline. The gradient and the
             * animation live in .skeleton-shimmer; they used to be repeated
             * here as well, which meant two places to change.
             */
            style={{ width, height, borderRadius, ...style }}
        />
    );
};

const range = (n) => Array.from({ length: n }, (_, i) => i);

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

/** A grid of card placeholders — wishlist, compare, any catalogue listing. */
export const CardGridSkeleton = ({ count = 4, className = '' }) => (
    <div className={className}>
        {range(count).map((i) => (
            <ProductCardSkeleton key={i} />
        ))}
    </div>
);

/**
 * Keeps the real headers so the columns do not resize when the rows land.
 */
export const TableSkeleton = ({ headers = [], rows = 5 }) => (
    <div className="admin-table-responsive">
        <table className="admin-table">
            <thead>
                <tr>
                    {headers.map((header) => (
                        <th key={header}>{header}</th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {range(rows).map((row) => (
                    <tr key={row}>
                        {headers.map((header) => (
                            <td key={header}>
                                <Skeleton height="14px" />
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    </div>
);

/** Cart and checkout lines: thumbnail, two lines of text, a price. */
export const LineItemsSkeleton = ({ count = 3 }) => (
    <div className="skeleton-line-items">
        {range(count).map((i) => (
            <div className="skeleton-line-item" key={i}>
                <Skeleton
                    width="72px"
                    height="72px"
                    borderRadius="var(--radius-sm, 8px)"
                    className="skeleton-line-item-thumb"
                />
                <div className="skeleton-line-item-body">
                    <Skeleton width="70%" height="16px" />
                    <Skeleton width="40%" height="13px" />
                </div>
                <Skeleton
                    width="88px"
                    height="20px"
                    className="skeleton-line-item-price"
                />
            </div>
        ))}
    </div>
);

/** The product page: gallery on the left, buying column on the right. */
export const ProductDetailSkeleton = () => (
    <div className="skeleton-pdp">
        <div className="skeleton-pdp-gallery">
            <Skeleton height="420px" borderRadius="var(--radius-md, 12px)" />
            <div className="skeleton-pdp-thumbs">
                {range(4).map((i) => (
                    <Skeleton
                        key={i}
                        height="72px"
                        borderRadius="var(--radius-sm, 8px)"
                    />
                ))}
            </div>
        </div>
        <div className="skeleton-pdp-info">
            <Skeleton width="30%" height="14px" />
            <Skeleton width="85%" height="30px" />
            <Skeleton width="45%" height="16px" />
            <Skeleton width="35%" height="34px" />
            <Skeleton height="1px" />
            {range(3).map((i) => (
                <Skeleton key={i} width={`${85 - i * 12}%`} height="14px" />
            ))}
            <div className="skeleton-pdp-actions">
                <Skeleton height="46px" borderRadius="var(--radius-sm, 8px)" />
                <Skeleton height="46px" borderRadius="var(--radius-sm, 8px)" />
            </div>
        </div>
    </div>
);

/** One row per component slot, matching the builder's four-column grid. */
export const BuilderRowsSkeleton = ({ count = 8 }) => (
    <div className="pc-builder-components-table">
        {range(count).map((i) => (
            <div className="pc-builder-row" key={i}>
                <div className="component-type-col">
                    <Skeleton
                        width="42px"
                        height="42px"
                        borderRadius="var(--radius-sm, 8px)"
                    />
                    <div className="skeleton-stack">
                        <Skeleton width="120px" height="15px" />
                        <Skeleton width="170px" height="12px" />
                    </div>
                </div>
                <div className="component-content-col">
                    <Skeleton width="55%" height="14px" />
                </div>
                <Skeleton width="70px" height="16px" />
                <Skeleton width="90px" height="32px" />
            </div>
        ))}
    </div>
);

/**
 * The two filter sections that only exist once the facets arrive.
 *
 * Category links are Inertia visits, so choosing one remounts the listing and
 * the facets start again from nothing. Without a placeholder the sidebar
 * dropped from roughly 2300px to 720px and sprang back a moment later, which
 * shoved the page around on every category click.
 */
export const FilterFacetSkeleton = () => (
    <>
        <section className="plp-filter-group">
            <div className="plp-filter-legend" aria-hidden="true">
                <h4>Category</h4>
            </div>
            {/* The real section carries a search box above six or more rows. */}
            <div className="plp-filter-skeleton-rows">
                <Skeleton height="34px" />
                {range(8).map((i) => (
                    <Skeleton key={i} height="17px" width={`${88 - i * 6}%`} />
                ))}
            </div>
        </section>

        <section className="plp-filter-group">
            <div className="plp-filter-legend" aria-hidden="true">
                <h4>Brand</h4>
            </div>
            <div className="plp-filter-skeleton-rows">
                <Skeleton height="34px" />
                {range(8).map((i) => (
                    <Skeleton key={i} height="17px" width={`${76 - i * 4}%`} />
                ))}
            </div>
        </section>
    </>
);

export default Skeleton;
