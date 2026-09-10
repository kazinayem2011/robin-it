import siteConfig from '../constants/siteConfig';

/**
 * A product's photographs, as a list of paths.
 *
 * Shared so the admin and the storefront cannot disagree about what a product
 * has: the shop's gallery derived this inline, and the admin — which shows the
 * same photos in its list and its detail panel — had no list at all, only the
 * one thumbnail the row draws.
 *
 * A product with no photos still returns one entry. Somewhere to look is
 * better than an empty viewer, and every caller here draws something.
 */
export const photosOf = (product) => {
    const photos = (product?.images ?? [])
        .map((image) => image?.image_path)
        .filter(Boolean);

    if (photos.length > 0) return photos;

    return [
        product?.image_path ||
            siteConfig.productPlaceholder ||
            '/images/product-placeholder.svg',
    ];
};

export default photosOf;
