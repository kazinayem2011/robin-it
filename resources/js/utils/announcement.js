/**
 * Split the ticker into a heading that stays put and a message that scrolls.
 *
 * The heading is whatever precedes the first colon — "⚡ Flash Deals Live:" —
 * which is how these are already written, so nothing has to be reconfigured
 * for it to work. A colon appearing late in the sentence is punctuation rather
 * than a label, so past a short cutoff the whole line scrolls instead; the same
 * is true when there is no colon at all.
 */
const MAX_LABEL_LENGTH = 40;

export const splitAnnouncement = (text) => {
    const full = String(text ?? '').trim();

    if (!full) return { label: '', message: '' };

    const colon = full.indexOf(':');

    if (colon === -1 || colon >= MAX_LABEL_LENGTH) {
        return { label: '', message: full };
    }

    const message = full.slice(colon + 1).trim();

    // A heading with nothing after it is just the announcement.
    if (!message) return { label: '', message: full };

    return { label: full.slice(0, colon + 1).trim(), message };
};

export default splitAnnouncement;
