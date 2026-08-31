/**
 * The page numbers to actually draw.
 *
 * Laravel's paginator sends a window of its own — with the default
 * `onEachSide` that is thirteen entries on a 51-page table, so the admin
 * footer read `1 2 3 4 5 6 7 8 9 10 … 50 51`. Nobody navigates by jumping to
 * page 7; they step forward, or they jump to the end. The extra buttons are
 * a wall of identical squares that pushes the useful controls off to the side.
 *
 * So the window is decided here instead, at a fixed seven slots however many
 * pages there are: the first, the last, the current with a neighbour on each
 * side, and an ellipsis wherever a run was cut.
 *
 * Returns numbers and the string '…' for the gaps.
 */
export const ELLIPSIS = '…';

export function paginationWindow(currentPage, totalPages, siblingCount = 1) {
    const total = Math.max(0, Math.floor(totalPages) || 0);

    if (total <= 1) return total === 1 ? [1] : [];

    const current = Math.min(Math.max(1, Math.floor(currentPage) || 1), total);

    // 2 boundaries + 2 siblings + the current page + 2 ellipses. Below this
    // there is nothing to hide, and an ellipsis standing in for a single page
    // is wider than the page it replaces.
    const slots = siblingCount * 2 + 5;

    if (total <= slots) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const left = Math.max(current - siblingCount, 1);
    const right = Math.min(current + siblingCount, total);

    const hasLeftGap = left > 2;
    const hasRightGap = right < total - 1;

    const range = (from, to) =>
        Array.from({ length: to - from + 1 }, (_, i) => from + i);

    if (!hasLeftGap && hasRightGap) {
        return [...range(1, siblingCount * 2 + 3), ELLIPSIS, total];
    }

    if (hasLeftGap && !hasRightGap) {
        return [1, ELLIPSIS, ...range(total - (siblingCount * 2 + 2), total)];
    }

    return [1, ELLIPSIS, ...range(left, right), ELLIPSIS, total];
}

/**
 * Every paginator URL carries `page=N`, so one of them is a template for all
 * of them. Without this the component could only link to the pages Laravel
 * happened to include in its own window.
 */
export function pageUrlFactory(links = []) {
    const sample = links.find(
        (link) => link?.url && /[?&]page=\d+/.test(link.url),
    )?.url;

    if (!sample) return () => null;

    return (page) => sample.replace(/([?&]page=)\d+/, `$1${page}`);
}

/** currentPage / totalPages as the paginator links describe them. */
export function readLinks(links = []) {
    const numeric = links.filter((link) => /^\d+$/.test(String(link?.label)));

    const totalPages = numeric.reduce(
        (max, link) => Math.max(max, Number(link.label)),
        0,
    );

    const active = numeric.find((link) => link.active);
    const currentPage = active ? Number(active.label) : 1;

    return { currentPage, totalPages };
}
