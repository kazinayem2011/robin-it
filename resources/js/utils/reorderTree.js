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
 * The children of one node, wherever it sits.
 *
 * Both functions below used to look one level down and no further, which was
 * enough while only roots and their children could be dragged. The third level
 * is where the makers live — a dozen or more under a single shelf, in an order
 * somebody cares about — and they could not be moved at all.
 *
 * @returns {Array|null} the node's children, or null if the node is not here
 */
const childrenOf = (tree, parentId) => {
    if (!Array.isArray(tree)) return null;

    for (const node of tree) {
        if (node.id === parentId) return node.children || [];

        const deeper = childrenOf(node.children, parentId);

        if (deeper !== null) return deeper;
    }

    return null;
};

/**
 * The tree with one node's children rewritten, copied the whole way down to it.
 *
 * Copied rather than mutated: it is Inertia's prop, and a card drawn from a
 * mutated array does not re-render.
 */
const withChildren = (tree, parentId, rewrite) => {
    if (!Array.isArray(tree)) return tree;

    let changed = false;

    const next = tree.map((node) => {
        if (node.id === parentId) {
            const children = rewrite(node.children || []);

            if (children === node.children) return node;

            changed = true;

            return { ...node, children };
        }

        const deeper = withChildren(node.children, parentId, rewrite);

        if (deeper === node.children) return node;

        changed = true;

        return { ...node, children: deeper };
    });

    return changed ? next : tree;
};

/**
 * Moving a row within the shelf it already belongs to, at any depth.
 *
 * `parentId` of null means the roots. Positions are per parent, so this only
 * ever rewrites one list and never carries a row into another parent — the
 * same rule the server enforces, which keys on parent_id and so has always
 * been able to do this.
 */
export const reorderSiblings = (tree, parentId, from, to) => {
    if (!Array.isArray(tree)) return tree;

    if (parentId == null) return moveItem(tree, from, to);

    return withChildren(tree, parentId, (children) =>
        moveItem(children, from, to),
    );
};

/** Where a row currently sits on its shelf, or -1 if it is not on it. */
export const indexOnShelf = (tree, parentId, id) => {
    if (!Array.isArray(tree)) return -1;

    const shelf = parentId == null ? tree : childrenOf(tree, parentId);

    return (shelf || []).findIndex((row) => row.id === id);
};
