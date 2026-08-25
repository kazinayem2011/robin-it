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
 */

/**
 * Sorts the listing offers; anything else in the URL is ignored.
 *
 * These must stay in step with the API, which validates
 * `in:latest,price_low_high,price_high_low,name_asc` — a value this list lets
 * through but the backend rejects would 422 the whole listing.
 */
const SORTS = ['latest', 'price_low_high', 'price_high_low', 'name_asc'];

const DEFAULT_SORT = 'latest';

const toPositiveInt = (raw) => {
    const n = Number.parseInt(raw, 10);

    return Number.isFinite(n) && n > 0 ? n : null;
};

/**
 * @param {string} search - `window.location.search`
 * @returns {{page: number, sort: string, filters: object}}
 */
export const parseShopQuery = (search = '') => {
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

    const sort = params.get('sort');

    return {
        page: toPositiveInt(params.get('page')) ?? 1,
        sort: SORTS.includes(sort) ? sort : DEFAULT_SORT,
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
} = {}) => {
    const params = new URLSearchParams();

    if (filters.min_price) params.set('min_price', String(filters.min_price));
    if (filters.max_price) params.set('max_price', String(filters.max_price));

    if (Array.isArray(filters.brand_ids) && filters.brand_ids.length) {
        params.set('brand_ids', filters.brand_ids.join(','));
    }

    if (filters.in_stock) params.set('in_stock', '1');
    if (filters.on_sale) params.set('on_sale', '1');

    if (sort && sort !== DEFAULT_SORT) params.set('sort', sort);
    if (page > 1) params.set('page', String(page));

    const query = params.toString();

    return query ? `?${query}` : '';
};
