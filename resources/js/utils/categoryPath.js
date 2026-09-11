/**
 * Where a shelf sits in the tree, written for a reader: "Laptop › Gaming Laptop".
 *
 * The tree repeats names deliberately — four shelves are called Asus, one under
 * each parent, and several are called Accessories — so a category named on its
 * own names none of them in particular. Every place that shows one therefore
 * shows its ancestry, and they had each grown their own copy of this: the
 * picker, the chips, the admin list and the details panel all wrote it
 * slightly differently, and two of them wrote nothing at all.
 *
 * Two levels, which is what the category search returns and as deep as this
 * tree goes before the names are distinct on their own.
 *
 * @param {{parent?: {name?: string, parent?: {name?: string}}}|null} category
 * @returns {string} the ancestors, nearest last, or '' at the top of the tree
 */
export const categoryPath = (category) =>
    [category?.parent?.parent?.name, category?.parent?.name]
        .filter(Boolean)
        .join(' › ');

/**
 * The same, with the shelf's own name on the end: "Laptop › Gaming Laptop › Asus".
 *
 * @param {{name?: string}|null} category
 * @returns {string}
 */
export const categoryFullName = (category) => {
    if (!category?.name) return '';

    const path = categoryPath(category);

    return path ? `${path} › ${category.name}` : category.name;
};
