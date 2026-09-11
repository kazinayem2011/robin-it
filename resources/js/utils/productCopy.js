/**
 * What a copied product may not inherit.
 *
 * The split is between describing the goods and identifying a particular one.
 * A copy keeps the description — the shelf, the prices, the warranty wording,
 * the spec sheet, the filter answers — and starts empty on anything that
 * points at one specific item, because carrying that across is not merely
 * untidy, it is wrong in a way somebody downstream will act on.
 *
 * Nothing here is a substitute for the checks on save. The barcode is unique
 * in the database and the request refuses a duplicate; this only means the
 * form does not offer one in the first place.
 */

/** Cleared, with why, so the form can say what it emptied and the reader can argue. */
export const CLEARED_BY_COPY = [
    {
        field: 'barcode',
        label: 'Barcode',
        why: 'the number on this shop’s own box, and unique to one product',
    },
    {
        field: 'mpn',
        label: 'MPN',
        why: 'the manufacturer’s part number — a customer pastes it into Google to check the revision, so the wrong one sends them to the wrong machine',
    },
];

/*
 * Deliberately kept, and the two worth saying out loud:
 *
 *   model   two builds of one machine share it — "a shop that stocks both the
 *           8GB and 16GB build of one model has the same model string on two
 *           products" — which is exactly what copying is usually for.
 *   photos  usually right for the near-identical product a copy is made to
 *           become, and trivially replaced when they are not.
 *
 * Both are visible in the form before anything is saved, which is the point of
 * filling it in rather than writing a row.
 */

/**
 * The source product, shaped for a form that is about to create something new.
 *
 * @param {object} product as the API returns it
 * @returns {object} the same, minus its identity, and named as a copy
 */
export const withoutIdentity = (product) => {
    const copy = { ...product };

    for (const { field } of CLEARED_BY_COPY) {
        copy[field] = '';
    }

    // Per option, for the same reasons: both are unique across the whole shop,
    // and a saved copy would be refused on the first of them.
    copy.variants = (product.variants || []).map((variant) => ({
        ...variant,
        id: undefined,
        sku: '',
        barcode: '',
        stock_quantity: 0,
    }));

    /*
     * Rows carry ids so an edit can update rather than replace. A copy is
     * creating, so an inherited id would point at the original's row — the
     * gallery in particular would move the photographs rather than share them.
     */
    copy.id = undefined;
    copy.images = (product.images || []).map(({ id: _id, ...image }) => image);
    copy.specifications = (product.specifications || []).map(
        ({ id: _id, ...spec }) => spec,
    );

    copy.name = copyName(product.name || '');

    return copy;
};

/**
 * "RTX 4090" becomes "RTX 4090 (Copy)", and a copy of that stays "(Copy)".
 *
 * Not numbered: nothing is saved yet, so there is no list to be unique in, and
 * the name is expected to be typed over before it ever reaches one.
 */
export const copyName = (name) => {
    const base = name.replace(/\s*\(Copy(?:\s+\d+)?\)$/u, '').trim();

    return base ? `${base} (Copy)` : '';
};
