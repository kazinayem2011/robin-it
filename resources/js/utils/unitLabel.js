/**
 * How a product reads in a picker: its name, and the shelf it sits on.
 *
 * Names repeat. A catalogue laid out by maker has a "Sample AJAZZ" on the
 * keyboard shelf, another on the mouse shelf and two more besides, so a list of
 * names alone offers four identical lines and no way to choose between them.
 * The shelf is what tells them apart, and it is what the stock table already
 * shows under each name.
 *
 * @param {{name: string, category?: {name?: string}}} product
 * @param {{name: string}|null} variant The option, where one was chosen.
 */
export const unitLabel = (product, variant = null) => {
    const named = variant
        ? `${product?.name} (${variant.name})`
        : product?.name;
    const shelf = product?.category?.name;

    return shelf ? `${named} — ${shelf}` : `${named}`;
};

export default unitLabel;
