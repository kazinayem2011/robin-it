import React from 'react';

/**
 * Specs arranged into the sections the admin gave them, preserving entry order
 * both between groups and within one.
 *
 * Rows with no group collect under an empty key rather than being dropped or
 * given an invented heading: every spec written before grouping existed has a
 * null group, and those products still have to render.
 */
export const groupSpecifications = (specifications) => {
    const order = [];
    const bucket = new Map();

    specifications.forEach((spec) => {
        const group = (spec.group || '').trim();

        if (!bucket.has(group)) {
            bucket.set(group, []);
            order.push(group);
        }

        bucket.get(group).push(spec);
    });

    return order.map((group) => ({ group, items: bucket.get(group) }));
};

/**
 * The full specification table.
 *
 * Its own component because it is read on the page now rather than behind a
 * tab, and because the product page was thirteen hundred lines with this and
 * the description inlined in the middle of it.
 */
export default function ProductSpecifications({ specifications = [] }) {
    return (
        <div className="specifications-table">
            {specifications.length > 0 ? (
                <table>
                    {/* Grouped into sections, in the order the admin entered
                        them. A product whose specs predate grouping has no
                        `group` on any row and renders as the plain two-column
                        table it always was. */}
                    {groupSpecifications(specifications).map(
                        ({ group, items }) => (
                            <tbody key={group || '__none'}>
                                {group && (
                                    <tr className="spec-group-row">
                                        <th
                                            colSpan={2}
                                            scope="colgroup"
                                            className="spec-group"
                                        >
                                            {group}
                                        </th>
                                    </tr>
                                )}
                                {items.map((spec) => (
                                    <tr key={spec.id}>
                                        <td className="spec-name">
                                            {spec.name}
                                        </td>
                                        <td className="spec-value">
                                            {spec.value}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        ),
                    )}
                </table>
            ) : (
                <p>
                    {/* Says what is true. It used to read "Standard official
                        specifications apply", which claims a spec sheet exists
                        and sends the reader looking for one that was never
                        entered. */}
                    We have not published a specification sheet for this product
                    yet. Ask us and we will confirm any detail you need.
                </p>
            )}
        </div>
    );
}
