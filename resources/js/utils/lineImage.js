/**
 * The photo for a cart, checkout or order line.
 *
 * An option can carry its own photos — a white card looks nothing like the
 * black one — and `image_url` on the variant is its lead one, kept in step by
 * ProductGalleryService every time that option's gallery changes.
 *
 * Nothing outside the product page was reading it. Every line rendered from
 * the product alone, so buying the 32GB option showed the product's generic
 * shot, and choosing a different lead photo for an option changed nothing
 * anywhere a customer would look afterwards — which is indistinguishable from
 * the choice not saving.
 *
 * Returns null when the option has no photo of its own, so the caller falls
 * through to the product's own lead shot rather than to the placeholder.
 *
 * @param {{variant?: {image_url?: string|null}|null}|null|undefined} line
 * @returns {string|null}
 */
export const lineImageSrc = (line) => line?.variant?.image_url || null;
