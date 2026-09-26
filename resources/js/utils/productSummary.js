/**
 * A few sentences about a product, from the description the shop wrote.
 *
 * Descriptions here are pasted markup that open by repeating the product's
 * name as a heading ("HP 15-fc0626AU … Laptop" and then "The HP 15-fc0626AU
 * Laptop is powered by…"), so the name is dropped and the prose that follows is
 * cut at a sentence, never mid-word, to about `limit` characters.
 *
 * Falls back to the short description when there is no description at all.
 * Quick View shows the short description on its own as well (shortSummary),
 * and drops whichever of the two would repeat the other.
 */
export function productSummary(product, limit = 260) {
    if (!product) return '';

    const name = normalise(product.name);
    const blocks = textBlocks(product.description);

    /*
     * Headings off the front: short, and not a sentence. Matching the name
     * exactly is not enough — the heading is often the name as it was when
     * the text was pasted, before the listing's own title was edited.
     */
    while (blocks.length > 1 && isHeading(blocks[0])) {
        blocks.shift();
    }

    let text = blocks.join(' ').replace(/\s+/g, ' ').trim();

    // The name run into the first sentence with no space ("…LaptopThe HP").
    if (name && normalise(text).startsWith(name)) {
        text = text.slice(product.name.trim().length).trim();
    }

    if (!text) {
        const short = textBlocks(product.short_description).join(' ').trim();

        return normalise(short) === name ? '' : clip(short, limit);
    }

    return clip(text, limit);
}

/**
 * The short description as one line: what the admin form calls "Short
 * Summary / Key Highlights", written by the shop to sit under the name.
 *
 * Highlights are often one per line; they read as one line joined by a dot.
 * Empty when it is only the product's name again, which says nothing.
 */
export function shortSummary(product, limit = 200) {
    if (!product) return '';

    // Split on the lines as typed, before the markup's whitespace is folded.
    const text = String(product.short_description ?? '')
        .split(/\r?\n|<br\s*\/?>/i)
        .flatMap((line) => textBlocks(line))
        .map((line) => line.trim())
        .filter(Boolean)
        .join(' · ');

    if (!text || normalise(text) === normalise(product.name)) return '';

    return clip(text, limit);
}

/** The text of each block of the markup, in order. */
function textBlocks(html) {
    if (!html || typeof html !== 'string') return [];
    if (typeof DOMParser === 'undefined') {
        return [html.replace(/<[^>]*>/g, ' ')];
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');

    return [...doc.body.childNodes]
        .map((node) => node.textContent ?? '')
        .map((text) => text.replace(/\s+/g, ' ').trim())
        .filter(Boolean);
}

/** Whole sentences up to the limit; a single long one is cut at a word. */
function clip(text, limit) {
    if (text.length <= limit) return text;

    // A stop ends a sentence only before a space or the end, so "4.1 GHz"
    // and '15.6" FHD' stay whole: splitting on every stop began the summary
    // at "1 GHz and four cores…".
    const sentences = text.match(/[\s\S]*?[.!?]+(?=\s|$)\s*/g) ?? [];
    let out = '';

    for (const sentence of sentences) {
        if ((out + sentence).trim().length > limit) break;
        out += sentence;
    }

    if (out.trim()) return out.trim();

    const cut = text.slice(0, limit);

    return `${cut.slice(0, cut.lastIndexOf(' ')).replace(/[,;:\s]+$/, '')}…`;
}

function isHeading(block) {
    return block.length <= 120 && !/[.!?]$/.test(block);
}

function normalise(value) {
    return String(value ?? '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}
