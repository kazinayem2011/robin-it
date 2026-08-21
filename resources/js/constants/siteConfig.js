/**
 * ROBINS COMPUTER — SINGLE SOURCE OF TRUTH (SSOT)
 * Central site configuration, branding metadata, contact points, and navigation links.
 */

export const siteConfig = {
    name: 'Robins Computer',
    tagline: 'The Store of Technology',
    legalName: 'Robins Computer & Technology Ltd.',
    hotline: '16793',
    hotlineHours: '9:00 AM - 8:00 PM (Everyday)',
    salesEmail: 'sales@robinscomputer.com.bd',
    supportEmail: 'support@robinscomputer.com.bd',

    headOffice: 'Level 4, IDB Bhaban, Agargaon, Dhaka-1207',
    serviceCenter: 'Multiplan Center, Dhaka-1205',

    logo: {
        src: '/images/logo.png',
        alt: 'Robins Computer — The Store of Technology',
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

    // Search Category Filter Options
    searchCategories: [
        { label: 'All Tech', value: 'all' },
        { label: 'Components', value: 'components' },
        { label: 'Laptops', value: 'laptops' },
        { label: 'Desktop PC', value: 'desktop' },
        { label: 'Monitors', value: 'monitors' },
        { label: 'Gaming Gear', value: 'gaming' },
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
