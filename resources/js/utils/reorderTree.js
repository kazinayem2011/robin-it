/**
 * Moving a category within the shelf it already belongs to.
 *
 * Kept apart from the page because a drag is a stream of events and the page
 * has to answer each one instantly: the list rearranges under the cursor as
 * you move, and only the drop is sent to the server. That preview is this
 * function, called once per row you cross.
 */

/**
 * The list with one item lifted out and put back at another index.
 *
 * `to` is clamped rather than refused: dragging below the last row is how
 * somebody says "put it last", and the pointer is usually past the end of the
 * list by then.
 */
export const moveItem = (list, from, to) => {
    if (!Array.isArray(list) || list.length === 0) return list;
    if (from < 0 || from >= list.length) return list;

    const target = Math.max(0, Math.min(to, list.length - 1));
    if (target === from) return list;

    const next = list.slice();
    const [item] = next.splice(from, 1);
    next.splice(target, 0, item);

    return next;
};

/**
 * The same, applied to one shelf of the category tree.
 *
 * `parentId` of null means the roots. Positions are per parent, so this only
 * ever rewrites one list and never carries a row into another parent — the
 * same rule the server enforces.
 *
 * The tree is copied rather than mutated: it is Inertia's prop, and a card
 * that is drawn from a mutated array does not re-render.
 */
export const reorderSiblings = (tree, parentId, from, to) => {
    if (!Array.isArray(tree)) return tree;

    if (parentId == null) return moveItem(tree, from, to);

    return tree.map((parent) => {
        if (parent.id !== parentId) return parent;

        const children = moveItem(parent.children || [], from, to);
        return children === parent.children ? parent : { ...parent, children };
    });
};

/** Where a row currently sits on its shelf, or -1 if it is not on it. */
export const indexOnShelf = (tree, parentId, id) => {
    if (!Array.isArray(tree)) return -1;

    const shelf =
        parentId == null
            ? tree
            : tree.find((parent) => parent.id === parentId)?.children || [];

    return shelf.findIndex((row) => row.id === id);
};
