/**
 * ROBINS COMPUTER — CENTRALIZED API & ROUTE ENDPOINTS (SSOT)
 * Single source of truth for all frontend API calls and application route URLs.
 */

export const API_ENDPOINTS = {
    // "Tell me when this is back in stock."
    STOCK_NOTIFY: '/stock-notifications',
    STOCK_NOTIFY_COUNT: '/stock-notifications/count',

    // Categories API
    CATEGORIES: {
        MEGA_MENU: '/categories/mega-menu',
        FEATURED: '/categories/featured',
    },

    // Products API
    PRODUCTS: {
        FILTERS: '/products/filters',
        BRANCHES: (id) => `/products/${id}/branches`,
        LIST: '/products',
        DETAIL: (slug) => `/products/${slug}`,
        FLASH_SALE: '/products/flash-sale',
        FEATURED: '/products/featured',
        SUGGESTIONS: '/products/suggestions',
        BUILDER_SPECS: '/builder/quick-specs',
        PC_BUILDER_CATEGORIES: '/pc-builder/categories',
        PC_BUILDER_COMPONENTS: (categorySlug) =>
            `/pc-builder/components/${categorySlug}`,
    },

    // Orders Tracking API
    ORDERS: {
        TRACK: '/orders/track',
    },

    /*
     * Cart & checkout.
     *
     * Named `cart-api` / `checkout-api` while these were also registered at the
     * site root, where `/cart` and `/checkout` are the page routes. Those root
     * registrations are gone, so under the `/api` baseURL they can be named for
     * what they are.
     */
    CART: {
        BASE: '/cart',
        ITEM: (itemId) => `/cart/${itemId}`,
    },

    // Checkout & Order API
    CHECKOUT: {
        PROCESS: '/checkout',
    },

    // Wishlist API
    WISHLIST: {
        BASE: '/wishlist',
        ITEM: (productId) => `/wishlist/${productId}`,
    },

    // Comparison API
    COMPARE: {
        BASE: '/compare',
        ITEM: (productId) => `/compare/${productId}`,
    },

    // Customer Account / Dashboard Endpoints
    ACCOUNT: {
        PROFILE: '/account/profile',
        ADDRESS: '/account/address',
        ADDRESS_ITEM: (id) => `/account/address/${id}`,
        PASSWORD: '/account/password',
        AVATAR: '/account/avatar',
        ORDER_CANCEL: (id) => `/account/orders/${id}/cancel`,
    },

    // Blogs & Tech Journal API
    BLOGS: {
        LIST: '/blogs',
        DETAIL: (slug) => `/blogs/${slug}`,
    },

    // PC Builder API
    PC_BUILDER: {
        CATEGORIES: '/pc-builder/categories',
        COMPONENTS: (categorySlug) => `/pc-builder/components/${categorySlug}`,
        CHECK: '/pc-builder/check',
        SAVE: '/pc-builder/save',
        LOAD: (shareCode) => `/pc-builder/load/${shareCode}`,
    },

    // Reviews API
    REVIEWS: {
        LIST: (productSlug) => `/products/${productSlug}/reviews`,
        SUBMIT: (productSlug) => `/products/${productSlug}/reviews`,
    },

    // Warranty & RMA API
    WARRANTY: {
        CHECK: '/warranty/check',
        CLAIM: '/warranty/claim',
    },

    // Contact form and the footer's newsletter box
    CONTACT: '/contact',
    SUBSCRIBE: '/subscribe',

    // Coupons API
    COUPONS: {
        APPLY: '/coupons/apply',
    },

    // Stores & Showrooms API
    STORES: {
        LIST: '/stores',
    },

    // System Settings API
    SETTINGS: {
        PUBLIC: '/settings',
    },

    // Admin Dashboard Endpoints
    ADMIN: {
        // Running costs. Buying stock is not one of these — it is inventory
        // until it sells, and reaches the accounts as cost of goods sold.
        EXPENSES: '/admin/expenses',
        EXPENSE_ITEM: (id) => `/admin/expenses/${id}`,
        EXPENSE_CATEGORIES: '/admin/expense-categories',
        EXPENSE_CATEGORY_ITEM: (id) => `/admin/expense-categories/${id}`,
        DASHBOARD: '/admin/dashboard',
        ORDERS: '/admin/orders',
        ORDER_STATUS: (id) => `/admin/orders/${id}/status`,
        ORDER_DISPATCH: (id) => `/admin/orders/${id}/dispatch`,
        ORDER_REFUND: (id) => `/admin/orders/${id}/refund`,
        ORDER_PAYMENT: (id) => `/admin/orders/${id}/payment`,
        REFUND_ITEM: (id) => `/admin/refunds/${id}`,
        STAFF: '/admin/staff',
        STAFF_ITEM: (id) => `/admin/staff/${id}`,
        STOCK_COUNT: '/admin/stock/count',
        ROLES: '/admin/roles',
        ROLE_ITEM: (id) => `/admin/roles/${id}`,
        PAGES: '/admin/pages',
        PAGE_ITEM: (id) => `/admin/pages/${id}`,
        MESSAGE_REPLY: (id) => `/admin/messages/${id}/reply`,
        MESSAGE_STATUS: (id) => `/admin/messages/${id}/status`,
        SUBSCRIBER_ITEM: (id) => `/admin/subscribers/${id}`,
        COURIERS: '/admin/couriers',
        COURIER_ITEM: (id) => `/admin/couriers/${id}`,
        PRODUCTS: '/admin/products',
        PRODUCT_ITEM: (id) => `/admin/products/${id}`,
        CATEGORIES: '/admin/categories',
        CATEGORY_ITEM: (id) => `/admin/categories/${id}`,
        BANNERS: '/admin/banners',
        BANNER_ITEM: (id) => `/admin/banners/${id}`,
        COUPONS: '/admin/coupons',
        COUPON_ITEM: (id) => `/admin/coupons/${id}`,
        STORES: '/admin/stores',
        STORE_ITEM: (id) => `/admin/stores/${id}`,
        SETTINGS: '/admin/settings',
        SETTINGS_TEST_EMAIL: '/admin/settings/test-email',
        CUSTOMERS: '/admin/customers',
        BLOGS: '/admin/blogs',
        BLOG_ITEM: (id) => `/admin/blogs/${id}`,
        MEDIA: '/admin/media',
        REVIEWS: '/admin/reviews',
        REVIEW_ITEM: (id) => `/admin/reviews/${id}`,
        REVIEW_STATUS: (id) => `/admin/reviews/${id}/status`,
        WARRANTY_STATUS: (id) => `/admin/warranty/${id}/status`,

        // Inventory. Stock enters only through a receipt and is corrected only
        // by an audited adjustment — there is no "set the quantity" endpoint.
        STOCK_RECEIPTS: '/admin/stock/receipts',
        STOCK_ADJUST: '/admin/stock/adjust',
        STOCK_UNITS: '/admin/stock/units',
        COURIER_ZONES: (id) => `/admin/couriers/${id}/zones`,
        COURIER_ZONE: (id, zone) => `/admin/couriers/${id}/zones/${zone}`,
        BARCODE: '/admin/stock/barcode',
        ORDERS: '/admin/orders',
        ORDER_CUSTOMERS: '/admin/orders/customers',
        ORDER_LINES: (id) => `/admin/orders/${id}/lines`,
        CUSTOMER_ACTIVE: (id) => `/admin/customers/${id}/active`,
        CAMPAIGNS: '/admin/campaigns',
        CAMPAIGN: (id) => `/admin/campaigns/${id}`,
        CAMPAIGN_PREVIEW: '/admin/campaigns/preview',
        CAMPAIGN_PICKERS: '/admin/campaigns/pickers',
        CAMPAIGN_RECIPIENTS: (id) => `/admin/campaigns/${id}/recipients`,
        CAMPAIGN_SEND: (id) => `/admin/campaigns/${id}/send`,
        PURCHASE_ORDERS: '/admin/purchase-orders',
        PURCHASE_ORDER_SEND: (id) => `/admin/purchase-orders/${id}/send`,
        PURCHASE_ORDER_CANCEL: (id) => `/admin/purchase-orders/${id}/cancel`,
        PURCHASE_ORDER_RECEIVE: (id) => `/admin/purchase-orders/${id}/receive`,
        STOCK_SERIALS: '/admin/stock/serials',
        STOCK_SERIAL: (id) => `/admin/stock/serials/${id}`,
        STOCK_TRANSFER: '/admin/stock/transfer',
        STOCK_BRANCHES: (id) => `/admin/stock/products/${id}/branches`,
        SUPPLIERS: '/admin/suppliers',
        SUPPLIER_ITEM: (id) => `/admin/suppliers/${id}`,
        SUPPLIER_OPTIONS: '/admin/suppliers/options',
        STOCK_MOVEMENTS: (id) => `/admin/stock/products/${id}/movements`,
        ORDER_RETURN: (id) => `/admin/orders/${id}/return`,
    },
};

export const ROUTES = {
    HOME: '/',
    SHOP: '/shop',
    SHOP_CATEGORY: (slug) => `/shop/${slug}`,
    PRODUCT_DETAIL: (slug) => `/products/${slug}`,
    CART: '/cart',
    CHECKOUT: '/checkout',
    ORDER_SUCCESS: (orderNumber) =>
        `/order/success?order=${encodeURIComponent(orderNumber || '')}`,
    TRACK: '/track',
    PC_BUILDER: '/pc-builder',
    PC_BUILDER_CHOOSE: (categorySlug) => `/pc-builder/choose/${categorySlug}`,
    WISHLIST: '/wishlist',
    COMPARE: '/compare',
    STORES: '/stores',
    SUPPORT: '/support',
    OFFERS: '/offers',
    BLOGS: '/blogs',
    BLOG_DETAIL: (slug) => `/blogs/${slug}`,
    ABOUT: '/about',
    CONTACT: '/contact',
    TERMS: '/terms',
    RETURN_POLICY: '/return-policy',
    PRIVACY: '/privacy',
    WARRANTY: '/warranty',
    DASHBOARD: '/dashboard',
    DASHBOARD_ORDERS: '/dashboard/orders',
    DASHBOARD_WISHLIST: '/dashboard/wishlist',
    DASHBOARD_ADDRESSES: '/dashboard/addresses',
    DASHBOARD_PROFILE: '/dashboard/profile',
    ACCOUNT: '/account',
    PROFILE_EDIT: '/profile',
    PROFILE_DESTROY: '/profile',
    LOGIN: '/login',
    REGISTER: '/register',
    FORGOT_PASSWORD: '/forgot-password',
    FORGOT_PASSWORD_PHONE: '/forgot-password/mobile',
    OTP_REGISTER: '/otp/register',
    OTP_PASSWORD: '/otp/password',
    PASSWORD_RESET: '/reset-password',
    PASSWORD_UPDATE: '/password',
    PASSWORD_CONFIRM: '/confirm-password',
    EMAIL_VERIFICATION_NOTIFICATION: '/email/verification-notification',
    LOGOUT: '/logout',
    ADMIN_DASHBOARD: '/admin/dashboard',
    ADMIN_ORDERS: '/admin/orders',
    ADMIN_PRODUCTS: '/admin/products',
    ADMIN_STOCK: '/admin/stock',
    ADMIN_SUPPLIERS: '/admin/suppliers',
    ADMIN_CATEGORIES: '/admin/categories',
    ADMIN_BANNERS: '/admin/banners',
    ADMIN_COUPONS: '/admin/coupons',
    ADMIN_WARRANTY: '/admin/warranty',
    ADMIN_STORES: '/admin/stores',
    ADMIN_COURIERS: '/admin/couriers',
    ADMIN_REFUNDS: '/admin/refunds',
    ADMIN_STAFF: '/admin/staff',
    ADMIN_MESSAGES: '/admin/messages',
    ADMIN_PAGES: '/admin/pages',
    ADMIN_ROLES: '/admin/roles',
    ADMIN_STOCK_COUNT: '/admin/stock/count',
    ADMIN_PURCHASING: '/admin/purchase-orders',
    ADMIN_CAMPAIGNS: '/admin/campaigns',
    ADMIN_STOCK_SERIALS: '/admin/stock/serials',
    ADMIN_STOCK_ADJUSTMENTS: '/admin/stock/adjustments',
    ADMIN_SUBSCRIBERS: '/admin/subscribers',
    ADMIN_SETTINGS: '/admin/settings',
    ADMIN_CUSTOMERS: '/admin/customers',
    ADMIN_BLOGS: '/admin/blogs',
    ADMIN_REVIEWS: '/admin/reviews',
    ADMIN_EXPENSES: '/admin/expenses',
    ADMIN_EXPENSE_CATEGORIES: '/admin/expense-categories',
    ADMIN_REPORTS: '/admin/reports',
    ADMIN_REPORTS_PROFIT: '/admin/reports/profit-loss',
};

export default {
    API: API_ENDPOINTS,
    ROUTES,
};
