/**
 * Putting the server's field errors onto the form that caused them.
 *
 * The API answers a refused save with both: a sentence for the person — which
 * goes to a toast — and a map of which field it was about. Forms here showed
 * the sentence and dropped the map, so "Stock code UXSAME is on more than one
 * option here" arrived with nothing marked, and on a product with a dozen
 * options the reader had to go looking for the one it meant.
 */

/**
 * Formik keys a nested field `variants[1].sku`; Laravel names it
 * `variants.1.sku`. Same path, different punctuation.
 */
export const toFormikPath = (key) =>
    String(key).replace(/\.(\d+)(?=\.|$)/g, '[$1]');

/**
 * Mark each field the server complained about, with its first message.
 *
 * Touched as well as errored, because a field only shows its error once it has
 * been touched — and a field the person never reached is exactly the one they
 * need pointing at.
 *
 * Neither call revalidates: yup would run immediately and overwrite what the
 * server just said with its own opinion, which is how a rule the browser
 * cannot check — a code already used by another product — disappears the
 * instant it is reported. The next edit to that field clears it normally.
 *
 * @returns {number} how many fields were marked
 */
export const applyServerErrors = (formik, error) => {
    const errors = error?.errors;

    if (!errors || typeof errors !== 'object') return 0;

    let marked = 0;

    for (const [key, messages] of Object.entries(errors)) {
        const message = Array.isArray(messages) ? messages[0] : messages;

        if (!message) continue;

        const path = toFormikPath(key);

        formik.setFieldError(path, message);
        formik.setFieldTouched(path, true, false);
        marked += 1;
    }

    return marked;
};

export default applyServerErrors;
