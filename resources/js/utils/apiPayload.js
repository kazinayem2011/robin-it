/**
 * Reading what the API actually sends.
 *
 * Every admin response is wrapped — `{ error, code, message, data, meta }` —
 * and a paginated one wraps again, so a list of rows is `data.data`. Callers
 * were unwrapping that by hand, each in its own way, and each of them wrong in
 * a different direction:
 *
 *   Array.isArray(res) ? res : []        // the envelope is never an array,
 *                                        // so the delivery form's product
 *                                        // list was always empty and no stock
 *                                        // could be received at all
 *   res?.data || []                      // a paginator, not a list — .map on
 *                                        // it threw hard enough to blank the
 *                                        // whole stock page
 *   res?.movements?.data                 // a layer too shallow, so every
 *                                        // product's history came up empty
 *
 * None of them failed loudly. Two showed an empty list, which reads exactly
 * like having nothing yet.
 */

/**
 * The rows out of a response, however deeply they are wrapped.
 *
 * @param {*} response - the value a service returned
 * @returns {Array} the rows, or [] when there are none to find
 */
export const listFrom = (response) => {
    if (Array.isArray(response)) return response;

    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;

    return [];
};

/**
 * The object out of a response — an endpoint that returns one thing rather
 * than a list.
 *
 * @returns {object} the payload, or {} when there is none
 */
export const payloadFrom = (response) => {
    if (!response || Array.isArray(response)) return {};

    return response.data ?? response;
};
