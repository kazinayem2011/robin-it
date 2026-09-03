/**
 * The shop listing's state, expressed as a querystring.
 *
 * Paging, sorting and filtering all lived in React state alone, so the URL
 * stayed `/products` no matter what the shopper did. Reloading threw them back
 * to page one of an unfiltered catalogue, and a link to "page 2, in stock,
 * under ৳20,000" was impossible to send.
 *
 * These two functions are the whole contract: parse turns a querystring into
 * the listing's state, build turns that state back into a querystring. Both
 * are pure so they can be tested without a browser.
 *
 * The category is deliberately not in here. It is the route — `/shop/laptop`,
 * not `/shop?category_slug=laptop` — so a category page is a page, with its
 * own address, and not a filter that happens to be spelled like one.
 */

/**
 * Sorts the listing offers; anything else in the URL is ignored.
 *
 * These must stay in step with the API, which validates
 * `in:latest,price_low_high,price_high_low,name_asc,discount_high` — a value
 * this list lets through but the backend rejects would 422 the whole listing.
 */
const SORTS = [
    'latest',
    'price_low_high',
    'price_high_low',
    'name_asc',
    'discount_high',
];

const DEFAULT_SORT = 'latest';

/*
 * The shelf's own questions, as their own parameters.
 *
 * `?wi-fi-standard=wi-fi-6,wi-fi-7` rather than the numeric `?filter=837` the
 * shops that do this usually produce: a search engine can read it, and so can
 * whoever is sent the link. Anything else in the URL is left alone, so a
 * parameter this listing does not know about cannot be mistaken for a filter.
 */
const RESERVED = new Set([
    'min_price',
    'max_price',
    'brand_ids',
    'in_stock',
    'on_sale',
    'sort',
    'page',
    'q',
    'search',
]);

const toPositiveInt = (raw) => {
    const n = Number.parseInt(raw, 10);

    return Number.isFinite(n) && n > 0 ? n : null;
};

/**
 * @param {string} search - `window.location.search`
 * @param {string} defaultSort - what this listing sorts by when the URL says
 *   nothing. The offers page leads with the deepest discount rather than the
 *   newest arrival.
 * @returns {{page: number, sort: string, filters: object}}
 */
export const parseShopQuery = (search = '', defaultSort = DEFAULT_SORT) => {
    const params = new URLSearchParams(search);
    const filters = {};

    const min = toPositiveInt(params.get('min_price'));
    const max = toPositiveInt(params.get('max_price'));

    if (min !== null) filters.min_price = min;
    if (max !== null) filters.max_price = max;

    // Comma-joined so the URL stays readable, rather than brand_ids[]=2&…
    const brands = (params.get('brand_ids') || '')
        .split(',')
        .map(toPositiveInt)
        .filter((id) => id !== null);

    if (brands.length) filters.brand_ids = brands;

    // Present-and-"1" only: a stray `in_stock=0` should not read as true.
    if (params.get('in_stock') === '1') filters.in_stock = true;
    if (params.get('on_sale') === '1') filters.on_sale = true;

    /*
     * What the shopper typed.
     *
     * `search` was listed as reserved — so it was never mistaken for a shelf
     * filter — and then thrown away, because nothing here returned it and
     * nothing downstream asked for it. The header search box sent
     * `/shop?search=corsair`, this listing rebuilt the URL from its own state,
     * and the term vanished on arrival: the shopper searched and was shown all
     * 1,269 products.
     */
    const term = (params.get('search') || params.get('q') || '').trim();

    if (term) filters.search = term;

    const attributes = {};

    for (const [key, raw] of params.entries()) {
        if (RESERVED.has(key)) continue;

        const picked = raw
            .split(',')
            .map((v) => v.trim())
            .filter(Boolean);

        if (picked.length) attributes[key] = picked;
    }

    if (Object.keys(attributes).length) filters.attributes = attributes;

    const sort = params.get('sort');

    return {
        page: toPositiveInt(params.get('page')) ?? 1,
        sort: SORTS.includes(sort) ? sort : defaultSort,
        filters,
    };
};

/**
 * The inverse. Defaults are omitted so an untouched listing keeps the bare
 * `/products` URL it has always had.
 *
 * @returns {string} e.g. `?in_stock=1&page=2` — empty string when nothing is set
 */
export const buildShopSearch = ({
    page = 1,
    sort = DEFAULT_SORT,
    filters = {},
    defaultSort = DEFAULT_SORT,
} = {}) => {
    const params = new URLSearchParams();

    if (filters.min_price) params.set('min_price', String(filters.min_price));
    if (filters.max_price) params.set('max_price', String(filters.max_price));

    if (Array.isArray(filters.brand_ids) && filters.brand_ids.length) {
        params.set('brand_ids', filters.brand_ids.join(','));
    }

    if (filters.in_stock) params.set('in_stock', '1');
    if (filters.on_sale) params.set('on_sale', '1');
    if (filters.search) params.set('search', filters.search);

    // Sorted, so the same selection always produces the same address — two
    // shoppers ticking the same boxes in a different order share one link.
    Object.entries(filters.attributes ?? {})
        .filter(([, picked]) => Array.isArray(picked) && picked.length)
        .sort(([a], [b]) => a.localeCompare(b))
        .forEach(([slug, picked]) => {
            params.set(slug, [...picked].sort().join(','));
        });

    // Only worth naming when it differs from what this listing does anyway.
    if (sort && sort !== defaultSort) params.set('sort', sort);
    if (page > 1) params.set('page', String(page));

    const query = params.toString();

    return query ? `?${query}` : '';
};
