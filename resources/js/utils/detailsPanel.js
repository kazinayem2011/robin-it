/**
 * Which panel "View More Info" should open on the product page.
 *
 * The link sits under the Key Features summary and carries the reader down to
 * the full detail at the foot of the page. Two things make that a decision
 * rather than a plain anchor: the panel is a piece of state, so whoever last
 * read the reviews would otherwise be scrolled to the reviews; and a product
 * with no specifications would land on an empty table, which is worse than not
 * offering the jump at all.
 *
 * Specifications when there are any, the description when there are not, and
 * nothing when the product carries neither — in which case the link is not
 * drawn.
 *
 * @param {{specifications?: unknown[], description?: string}|null|undefined} product
 * @returns {'specifications'|'description'|null}
 */
export const detailsPanelFor = (product) => {
    if (product?.specifications?.length > 0) {
        return 'specifications';
    }

    // Whitespace is not a description. A product whose description is a stray
    // newline would otherwise offer a jump to a blank panel.
    if (
        typeof product?.description === 'string' &&
        product.description.trim()
    ) {
        return 'description';
    }

    return null;
};
