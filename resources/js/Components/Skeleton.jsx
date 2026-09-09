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
/**
 * The product page while it loads.
 *
 * It stood for a layout the page no longer has: two equal columns against the
 * real 1fr/2fr, a 420px gallery against a 4:3 one, and an info column of five
 * anonymous bars where there is now a title, a row of five chips, the key
 * features, and two payment cards. A skeleton that is the wrong shape moves
 * the content when it arrives, which is the one thing it exists to prevent.
 *
 * The widths of the chips are deliberately uneven — "Brand: Lenovo" is not the
 * width of "Regular Price: 82,500" — because a row of five identical pills
 * reads as a control rather than as text about to appear.
 */
const CHIP_WIDTHS = ['104px', '150px', '112px', '138px', '92px'];

export const ProductDetailSkeleton = () => (
    <>
        {/* Share, Save and Compare, which sit above the two columns. */}
        <div className="skeleton-pdp-utility">
            <Skeleton width="150px" height="22px" />
            <Skeleton width="210px" height="22px" />
        </div>

        <div className="skeleton-pdp">
            <div className="skeleton-pdp-gallery">
                {/* 4:3, the same ratio the real frame states, so the column
                    below it does not jump when the photo lands. */}
                <Skeleton
                    height="auto"
                    borderRadius="var(--radius-md, 12px)"
                    style={{ aspectRatio: '4 / 3' }}
                />
                <div className="skeleton-pdp-thumbs">
                    {range(4).map((i) => (
                        <Skeleton
                            key={i}
                            height="76px"
                            borderRadius="var(--radius-sm, 8px)"
                        />
                    ))}
                </div>
            </div>

            <div className="skeleton-pdp-info">
                {/* Title, two lines of it. */}
                <Skeleton width="92%" height="26px" />
                <Skeleton width="58%" height="26px" />

                {/* The fact row: price, regular price, status, code, brand. */}
                <div className="skeleton-pdp-chips">
                    {CHIP_WIDTHS.map((width, i) => (
                        <Skeleton
                            key={i}
                            width={width}
                            height="33px"
                            borderRadius="var(--radius-full, 999px)"
                        />
                    ))}
                </div>

                {/* Key Features: a heading and its bullets. */}
                <Skeleton width="140px" height="18px" />
                {range(5).map((i) => (
                    <Skeleton key={i} width={`${92 - i * 9}%`} height="13px" />
                ))}
                <Skeleton width="118px" height="14px" />

                {/* Payment Options: a heading and the two cards. */}
                <Skeleton width="160px" height="18px" />
                <div className="skeleton-pdp-pay">
                    {range(2).map((i) => (
                        <Skeleton
                            key={i}
                            height="82px"
                            borderRadius="var(--radius-md, 12px)"
                        />
                    ))}
                </div>

                {/* Quantity stepper beside the buy button. */}
                <div className="skeleton-pdp-actions">
                    <Skeleton
                        height="46px"
                        borderRadius="var(--radius-sm, 8px)"
                    />
                    <Skeleton
                        height="46px"
                        borderRadius="var(--radius-sm, 8px)"
                    />
                </div>
            </div>
        </div>
    </>
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
/**
 * Standing in for the one section of the filter panel that waits on the
 * server: Brand. Price and Availability draw themselves immediately, so they
 * are not placeholders.
 *
 * It used to lead with a Category group of its own, and kept doing so after
 * the category tree was taken out of the panel — so the shopper was shown a
 * section loading that was never going to arrive.
 */
export const FilterFacetSkeleton = () => (
    <section className="plp-filter-group">
        <div className="plp-filter-legend" aria-hidden="true">
            <h4>Brand</h4>
        </div>
        {/* The real section carries a search box above eight or more rows. */}
        <div className="plp-filter-skeleton-rows">
            <Skeleton height="34px" />
            {range(8).map((i) => (
                <Skeleton key={i} height="17px" width={`${76 - i * 4}%`} />
            ))}
        </div>
    </section>
);

export default Skeleton;
