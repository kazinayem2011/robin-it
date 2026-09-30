/**
 * Whether an option name is a colour — "Color", "Colour", "Frame colour".
 *
 * Only those carry a swatch: the product form offers a picker beside them,
 * and the shop shows the swatch on their values and nowhere else.
 */
export const isColourName = (name) => /colou?r/i.test(name || '');
