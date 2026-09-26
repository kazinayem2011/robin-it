/**
 * How many units of a product can be ordered, pre-order included.
 *
 * The client's copy of Product::sellableCeiling(). The product page and the
 * cart both capped their "+" at the stock on the shelf, which for a pre-order
 * product is nothing — so a customer allowed to pre-order three could only
 * ever take one, from either place. A pre-order product may go below the
 * shelf by its limit; with no limit set there is no ceiling at all.
 *
 * @param {object|null} product  carries allow_preorder and preorder_limit
 * @param {number|null} stock    on hand for this unit: the option's when there is one
 * @returns {number} the most that may be ordered; Infinity when uncapped
 */
export const orderableCeiling = (product, stock) => {
    const onHand = Number(stock ?? 0);

    if (!product?.allow_preorder) {
        return Math.max(0, onHand);
    }

    const limit = product.preorder_limit;

    if (limit === null || limit === undefined || limit === '') {
        return Number.POSITIVE_INFINITY;
    }

    return Math.max(0, onHand + Number(limit));
};

/**
 * Whether this many would be sold ahead of the delivery rather than off the
 * shelf — the line the cart marks, since those units ship later.
 */
export const preordersBeyondShelf = (product, stock, quantity) =>
    Boolean(product?.allow_preorder) && Number(quantity) > Number(stock ?? 0);

/** "3 October 2026", as the product page words the expected date; null when unset. */
export const preorderDate = (value) =>
    value
        ? new Date(value).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : null;

export default orderableCeiling;
