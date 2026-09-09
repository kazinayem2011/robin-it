/**
 * What the Status chip says, and whether that reads as good news or bad.
 *
 * Two things make this more than reading one field.
 *
 * The server's `stock_status_label` describes the *product*, and on a variant
 * product the stock belongs to the option. A product whose options total five
 * reports "In Stock" while the 16GB option is sold out — so selecting the
 * sold-out option showed "In Stock", which is the worst thing this line can
 * say.
 *
 * And the label is not a boolean. A shop can set its own out-of-stock wording,
 * so "2-3 Days" and "Discontinued" both arrive here: the first means the sale
 * is deferred, the second means it is lost. Painting every empty shelf red
 * would tell a customer to go elsewhere for something arriving on Tuesday.
 *
 * @param {object|null} product
 * @param {{selectedVariant?: object|null, availableStock?: number}} [state]
 * @returns {{label: string, tone: 'in'|'out'|'waiting'|'unknown'}}
 */
const PLAINLY_OUT = ['out of stock', 'unavailable'];

export const stockStatusFor = (product, state = {}) => {
    const { selectedVariant = null, availableStock = 0 } = state;

    if (!product) {
        return { label: '', tone: 'unknown' };
    }

    const hasVariants = Boolean(product.has_variants);

    // Nothing chosen yet, so there is no stock figure to report.
    if (hasVariants && !selectedVariant) {
        return { label: 'Choose an option', tone: 'unknown' };
    }

    if (availableStock > 0) {
        // The product-level label would be describing a different option.
        return {
            label: hasVariants
                ? 'In Stock'
                : product.stock_status_label || 'In Stock',
            tone: 'in',
        };
    }

    if (product.allow_preorder) {
        return { label: 'Pre-Order', tone: 'waiting' };
    }

    const label = hasVariants
        ? 'Out of Stock'
        : product.stock_status_label || 'Out of Stock';

    return {
        label,
        // A wording the shop chose itself is a promise about when, not a dead
        // end, so it is not painted as one.
        tone: PLAINLY_OUT.includes(label.trim().toLowerCase())
            ? 'out'
            : 'waiting',
    };
};
