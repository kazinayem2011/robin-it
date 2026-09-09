/**
 * The Product markup a search engine reads off the page.
 *
 * Pulled out of the page because it is the one output nobody looks at. It said
 * `availability: InStock` as a literal, so every sold-out product told Google
 * it could be bought, and it published `effective_price` while the page
 * headlined `checkout_price` — a mismatch between the markup and the visible
 * price is what a shopping result is rejected for. Both were wrong for months
 * and neither is visible on the page, which is exactly why this now has tests.
 *
 * Anything the shop has not recorded is left out rather than filled in. A
 * placeholder here does not read as a placeholder; it publishes a brand, a
 * part number or a rating that does not exist, straight into search results.
 *
 * @param {object} product
 * @param {{
 *   price: number,
 *   image?: string,
 *   inStock: boolean,
 *   sellerName: string,
 *   reviews?: {total_reviews?: number, average_rating?: number},
 *   variantOptions?: string[],
 * }} view  what the page is actually showing
 */
export const productSchemaFor = (product, view) => {
    if (!product) {
        return null;
    }

    const {
        price,
        image,
        inStock = false,
        sellerName,
        reviews = {},
    } = view || {};

    const availability = inStock
        ? 'InStock'
        : product.allow_preorder
          ? 'PreOrder'
          : 'OutOfStock';

    const reviewCount = Number(reviews.total_reviews) || 0;

    return {
        '@context': 'https://schema.org/',
        '@type': 'Product',
        name: product.name,
        ...(image ? { image } : {}),
        description: product.short_description || product.name,
        ...(product.brand?.name
            ? { brand: { '@type': 'Brand', name: product.brand.name } }
            : {}),
        ...(product.mpn ? { mpn: product.mpn } : {}),
        ...(product.model ? { model: product.model } : {}),
        offers: {
            '@type': 'Offer',
            priceCurrency: 'BDT',
            price,
            itemCondition: 'https://schema.org/NewCondition',
            availability: `https://schema.org/${availability}`,
            seller: { '@type': 'Organization', name: sellerName },
        },
        /*
         * Only with reviews behind it. schema.org requires a real count, and
         * an invented rating is the one piece of markup that gets a shop's
         * results suppressed rather than merely ignored — the page's own
         * display falls back to five stars when there are none, which must not
         * reach here.
         */
        ...(reviewCount > 0
            ? {
                  aggregateRating: {
                      '@type': 'AggregateRating',
                      ratingValue: reviews.average_rating,
                      reviewCount,
                  },
              }
            : {}),
    };
};
