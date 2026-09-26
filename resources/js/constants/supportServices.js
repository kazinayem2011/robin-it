/**
 * What a customer can ask the shop for on the Contact page.
 *
 * The form opens with this choice, as StarTech's service desk does: someone
 * with a broken laptop and someone asking about an order are different
 * requests, and knowing which before reading a word sends it to the right
 * person. Repairs first, then work done on a machine rather than to it, then
 * the questions a shop gets that are not about a machine at all — this is the
 * only form on the site, so it has to take those too.
 *
 * Stored as the message's subject, so the inbox and replies read it as before.
 */
export const SUPPORT_SERVICES = [
    'Laptop repair or servicing',
    'MacBook repair',
    'Desktop PC servicing',
    'Desktop motherboard repair',
    'All-in-One PC repair',
    'iMac repair',
    'Graphics card repair',
    'Monitor repair',
    'Printer repair or servicing',
    'Projector repair',
    'UPS repair or battery replacement',
    'Camera or DSLR repair',
    'Mobile phone repair',
    'TV repair',
    'Speaker or home theatre repair',
    'Data recovery',
    'Windows installation or virus removal',
    'Upgrade fitting (RAM, SSD, graphics card)',
    'Custom PC build and assembly',
    'Networking or Wi-Fi setup',
    'Warranty claim',
    'Order or delivery question',
    'Product or price enquiry',
    'Corporate or bulk quotation',
    'Something else',
];

/**
 * A service named in the address (`/contact?service=warranty-claim`, or the
 * words themselves), so another page can open the form already on it.
 */
export function serviceFromQuery(search) {
    try {
        const wanted = new URLSearchParams(search).get('service');
        if (!wanted) return '';

        const slug = (s) =>
            s
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '');

        return SUPPORT_SERVICES.find((s) => slug(s) === slug(wanted)) ?? '';
    } catch {
        return '';
    }
}
