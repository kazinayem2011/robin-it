/**
 * Key Features, as a list a person types rather than markup they author.
 *
 * The field is stored and rendered as HTML — the product page puts it straight
 * into the markup — but that is no reason to make a shopkeeper type
 * `<ul><li>`. The form takes one feature per line and converts; these are the
 * two halves of that conversion, kept together so they cannot drift.
 */

/** `<ul><li>a</li><li>b</li></ul>` → "a\nb", for editing what is stored. */
export const bulletsToLines = (html) => {
    if (!html) return '';

    // Parsed rather than regexed: a feature can legitimately contain markup
    // ("16GB <strong>DDR5</strong>"), and a regex would take that apart.
    const doc = new DOMParser().parseFromString(
        `<div>${html}</div>`,
        'text/html',
    );
    const items = [...doc.querySelectorAll('li')];

    if (items.length > 0) {
        return items.map((li) => li.textContent.trim()).join('\n');
    }

    // Anything that was not a list — a paragraph typed before this existed —
    // comes back as its text so it is not silently dropped on the next save.
    return (doc.body.textContent || '').trim();
};

/** "a\nb" → `<ul><li>a</li><li>b</li></ul>`, for storing what was typed. */
export const linesToBullets = (text) => {
    const lines = String(text || '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

    if (lines.length === 0) return '';

    const escape = (line) =>
        line.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    return `<ul>${lines.map((l) => `<li>${escape(l)}</li>`).join('')}</ul>`;
};
