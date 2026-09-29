/**
 * Whether a product can be ordered at all, and so how far its "+" may go.
 *
 * The storefront is never sent a stock figure — the shop does not tell its
 * customers how many units it has — so this works from yes/no answers only:
 * the unit is in stock, or the product is sold ahead of a delivery. Either
 * way there is no ceiling here; the caller caps it with the shop's per-item
 * limit, and the server refuses anything it cannot supply with a message that
 * names no number. Sold out and not on pre-order, nothing can be ordered.
 *
 * @param {object|null} product  carries allow_preorder
 * @param {boolean} inStock      whether this unit (the option, when there is
 *                               one) is in stock
 * @returns {number} Infinity when orderable, 0 when not
 */
export const orderableCeiling = (product, inStock) =>
    inStock || product?.allow_preorder ? Number.POSITIVE_INFINITY : 0;

/**
 * Whether a line is a pre-order: sold ahead of the delivery because the shelf
 * is empty and the product is set up for it. A product setting and a yes/no,
 * never a count — so the cart and checkout can mark the line without knowing
 * how many are on the shelf.
 */
export const isPreorderLine = (product, inStock) =>
    Boolean(product?.allow_preorder) && !inStock;

/**
 * Whether a cart line's unit is in stock: the option's answer when there is
 * one. A product arrives as a model (`in_stock`) or as a card (`inStock`).
 */
export const lineInStock = (item) =>
    Boolean(
        item?.product_variant_id || item?.variant
            ? item?.variant?.in_stock
            : (item?.product?.in_stock ?? item?.product?.inStock),
    );

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
