/**
 * Build completeness.
 *
 * A rig needs a processor, motherboard, memory, storage, a power supply and a
 * case before it will boot. The server marks those slots `required` when it
 * sends the builder its categories, and this reads that flag rather than
 * keeping a second list here that could drift away from it.
 *
 * What it deliberately does not do is decide whether the customer may buy.
 * Plenty of real orders are upgrades — a processor and board for a machine that
 * already has a case and a supply — so missing essentials are something to say
 * out loud, not something to refuse.
 */
export const essentialsStatus = (categories = [], items = []) => {
    const chosenIds = new Set(
        (items || []).map((item) => item?.componentId).filter(Boolean),
    );

    const essentials = (categories || []).filter((cat) => cat?.required);

    const missing = essentials
        .filter((cat) => !chosenIds.has(cat.id))
        .map((cat) => ({ id: cat.id, name: cat.name }));

    return {
        total: essentials.length,
        chosen: essentials.length - missing.length,
        missing,
        complete: essentials.length > 0 && missing.length === 0,
    };
};

/** "a Power Supply and a PC Case" — for a sentence, not a list. */
export const listNames = (entries = []) => {
    const names = entries.map((entry) => entry?.name).filter(Boolean);

    if (names.length === 0) return '';
    if (names.length === 1) return names[0];

    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
};

/**
 * Whether a product the builder is holding can be bought right now.
 *
 * The builder's own payload spells this `inStock` / `stockQuantity`, while the
 * catalogue elsewhere sends `stock_quantity`. Reading only one of them is how
 * every selected component came to be labelled "Out of Stock" while sitting on
 * a shelf of 23.
 */
export const isInStock = (product) => {
    if (!product) return false;

    if (typeof product.inStock === 'boolean') return product.inStock;

    const quantity = product.stockQuantity ?? product.stock_quantity;

    return Number(quantity ?? 0) > 0;
};

/**
 * What the badge on a chosen component should say.
 *
 * Three states, not two: on the shelf, orderable ahead of a delivery, or not
 * available at all. Collapsing the middle one into "Out of Stock" tells a
 * customer they cannot have something they can.
 */
export const stockLabel = (product) => {
    if (isInStock(product)) {
        return { text: 'In Stock', tone: '' };
    }

    const preorder = product?.preorder ?? product?.is_preorder ?? false;

    return preorder
        ? { text: 'Pre-order', tone: ' is-preorder' }
        : { text: 'Out of Stock', tone: ' is-out' };
};
