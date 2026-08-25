/**
 * Put the shop's name on a page title, once.
 *
 * Most pages already end their title with the brand, so appending it
 * unconditionally produced "Robins Computer — The Store of Technology — Robins
 * Computer" on the home page and "… — Robins Computer - Laravel" everywhere
 * else. Both the Inertia title callback and SEOHead build titles, so the rule
 * lives here rather than being written twice and drifting.
 */
export const withBrand = (title, brandName) => {
    const brand = String(brandName ?? '').trim();
    const page = String(title ?? '').trim();

    if (!brand) return page;
    if (!page) return brand;

    return page.includes(brand) ? page : `${page} — ${brand}`;
};

export default withBrand;
