/**
 * The product's spec table with one option's differences applied.
 *
 * An option carries only the rows that differ. A row whose name matches one
 * of the product's (ignoring case and spacing) replaces it where it stands;
 * any other goes after the last row of its group, or at the end when its
 * group is new. With no option, or an option that differs in nothing, the
 * product's table comes back unchanged.
 */
export const optionSpecs = (productRows = [], optionRows = []) => {
    const rows = (productRows || []).map((r) => ({ ...r }));
    const norm = (s) =>
        String(s || '')
            .trim()
            .toLowerCase()
            .replace(/\s+/g, ' ');

    for (const own of optionRows || []) {
        if (!own?.name || !own?.value) continue;

        const at = rows.findIndex((r) => norm(r.name) === norm(own.name));

        if (at >= 0) {
            rows[at] = { ...rows[at], value: own.value, from_option: true };
            continue;
        }

        const row = {
            group: own.group || null,
            name: own.name,
            value: own.value,
            from_option: true,
        };
        let last = -1;
        rows.forEach((r, i) => {
            if (own.group && norm(r.group) === norm(own.group)) last = i;
        });

        if (last >= 0) rows.splice(last + 1, 0, row);
        else rows.push(row);
    }

    return rows;
};

export default optionSpecs;
