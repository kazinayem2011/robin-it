/**
 * ROBINS COMPUTER — SINGLE SOURCE OF TRUTH (SSOT)
 * Central site configuration, branding metadata, contact points, and navigation links.
 */

/*
 * Branding comes from Site Settings, which the server shares with every page.
 *
 * These were plain literals, so the admin could edit the shop's name, address,
 * hotline or support address and the site carried on showing something else —
 * the footer's legal name and contact block, the header's phone number, 21
 * page titles. Worse, they had drifted apart: the hotline here said 16793
 * while the shop's actual number was 16789.
 *
 * The literals survive as the fallback, which is all that renders in the
 * moment before the first page props arrive, and all an install has before
 * anybody fills the settings in.
 */
const FALLBACKS = {
    name: 'Robins Computer',
    legalName: 'Robins Computer & Technology Ltd',
    tagline: 'The Store of Technology',
    hotline: '16789',
    hotlineHours: '9:00 AM - 8:00 PM (Everyday)',
    salesEmail: 'sales@robinscomputer.com.bd',
    supportEmail: 'support@robinscomputer.com.bd',
    headOffice: 'Level 4, IDB Bhaban, Agargaon, Dhaka-1207',
    serviceCenter: 'Multiplan Center, Dhaka-1205',
    footerNote: 'Built with Precision & Care.',
    logoSrc: '/images/logo.png',
};

/** Which setting key backs each field. */
const SETTING_KEYS = {
    name: 'site_name',
    legalName: 'site_legal_name',
    tagline: 'site_tagline',
    hotline: 'hotline_number',
    hotlineHours: 'hotline_hours',
    salesEmail: 'sales_email',
    supportEmail: 'support_email',
    headOffice: 'site_address',
    serviceCenter: 'service_center_address',
    footerNote: 'footer_note',
    logoSrc: 'site_logo',
};

/*
 * Fields where clearing the box means "show nothing", not "use the default".
 *
 * Everything else is load-bearing — a shop with no name or no phone number is
 * broken, so a blank there keeps the fallback. The footer note is decoration,
 * and an admin who empties it wants it gone.
 */
const CLEARABLE = new Set(['footerNote']);

const resolved = { ...FALLBACKS };

/** A setting only counts if it has something in it. */
const usable = (value) => typeof value === 'string' && value.trim() !== '';

/**
 * Apply the settings the server shared. Anything blank or missing keeps its
 * fallback rather than blanking the site.
 */
export const setSiteSettings = (settings) => {
    if (!settings || typeof settings !== 'object') return;

    for (const [field, key] of Object.entries(SETTING_KEYS)) {
        if (usable(settings[key])) {
            resolved[field] = settings[key].trim();
        } else if (CLEARABLE.has(field) && typeof settings[key] === 'string') {
            resolved[field] = '';
        }
    }
};

/**
 * The shop's name, resolved server-side — site_name can be absent from the
 * table entirely, and every page title needs something to fall back on.
 */
export const setBrandName = (name) => {
    if (usable(name)) {
        resolved.name = name.trim();
    }
};

export const siteConfig = {
    get name() {
        return resolved.name;
    },
    get tagline() {
        return resolved.tagline;
    },
    /*
     * No trailing full stop: the footer adds its own, so a legal name written
     * "… Ltd." rendered as "Robins Computer & Technology Ltd.. All Rights
     * Reserved".
     */
    get legalName() {
        return resolved.legalName.replace(/\.\s*$/, '');
    },
    get hotline() {
        return resolved.hotline;
    },
    get hotlineHours() {
        return resolved.hotlineHours;
    },
    get salesEmail() {
        return resolved.salesEmail;
    },
    get supportEmail() {
        return resolved.supportEmail;
    },
    get headOffice() {
        return resolved.headOffice;
    },
    get serviceCenter() {
        return resolved.serviceCenter;
    },
    /* Optional; the footer leaves the sentence off entirely when it is blank. */
    get footerNote() {
        return resolved.footerNote;
    },

    get logo() {
        return {
            src: resolved.logoSrc,
            alt: `${resolved.name} — ${resolved.tagline}`,
        };
    },
    productPlaceholder: '/images/product-placeholder.svg',

    // Trending Search Keywords (SSOT)
    trendingKeywords: [
        'RTX 4090',
        'Core i9 14900K',
        'Ryzen 7 7800X3D',
        'MacBook Air M3',
        'OLED 240Hz',
    ],

    // Global Key Value Perks
    perks: [
        {
            id: 'delivery',
            title: '64-District Express Delivery',
            sub: 'Guaranteed 24-48h dispatch across BD',
            icon: 'Truck',
        },
        {
            id: 'warranty',
            title: '100% Genuine Warranty',
            sub: 'Authorized direct brand distributors',
            icon: 'ShieldCheck',
        },
        {
            id: 'support',
            title: '24/7 Expert Support',
            sub: 'Dedicated tech helpdesk & claim support',
            icon: 'Headset',
        },
        {
            id: 'return',
            title: '7 Days Replacement',
            sub: 'Hassle-free hardware replacement policy',
            icon: 'RotateCcw',
        },
        {
            id: 'emi',
            title: '0% EMI Up to 36 Months',
            sub: 'Partnered with 28 leading BD banks',
            icon: 'CreditCard',
        },
    ],
};

export default siteConfig;
