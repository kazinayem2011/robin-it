<?php

namespace App\Constants;

/**
 * Single Source of Truth (SSOT) for Backend Route Paths and API Endpoints.
 */
class ApiEndpoints
{
    // API Prefix
    public const API_PREFIX = 'api';

    // Categories
    public const CATEGORIES_MEGA_MENU = 'categories/mega-menu';

    public const CATEGORIES_FEATURED = 'categories/featured';

    // Products
    public const PRODUCTS_INDEX = 'products';

    /** Price range and brands present in a selection, for the filter sidebar. */
    public const PRODUCTS_FILTERS = 'products/filters';

    /** "Tell me when this is back in stock." */
    public const STOCK_NOTIFY = 'stock-notifications';

    public const STOCK_NOTIFY_COUNT = 'stock-notifications/count';

    /** Which showrooms are holding something. */
    public const PRODUCT_BRANCHES = 'products/{id}/branches';

    public const PRODUCTS_SHOW = 'products/{slug}';

    public const PRODUCTS_FLASH_SALE = 'products/flash-sale';

    public const PRODUCTS_FEATURED = 'products/featured';

    public const PRODUCTS_SUGGESTIONS = 'products/suggestions';

    public const BUILDER_QUICK_SPECS = 'builder/quick-specs';

    public const PC_BUILDER_CATEGORIES = 'pc-builder/categories';

    public const PC_BUILDER_COMPONENTS = 'pc-builder/components/{categorySlug}';

    // Orders Tracking
    public const ORDERS_TRACK = 'orders/track';

    /*
     * Cart, checkout and comparison.
     *
     * These were named `cart-api` / `checkout-api` / `compare-api` because they
     * were also registered at the site root, where `/cart` and `/checkout` are
     * already the page routes. The root registrations are gone — the browser
     * client has always called them under /api — so they can be named for what
     * they are.
     */
    public const CART = 'cart';

    public const CART_ITEM = 'cart/{itemId}';

    // Checkout
    public const CHECKOUT = 'checkout';

    // Wishlist
    public const WISHLIST = 'wishlist';

    public const WISHLIST_ITEM = 'wishlist/{productId}';

    // Comparison
    public const COMPARE = 'compare';

    public const COMPARE_ITEM = 'compare/{productId}';

    // Banners & Promos
    public const BANNERS = 'banners';

    // Brands Featured
    public const BRANDS_FEATURED = 'brands/featured';

    // Coupons
    public const COUPONS_APPLY = 'coupons/apply';

    // Product Reviews
    public const PRODUCT_REVIEWS = 'products/{slug}/reviews';

    // Showrooms & Stores
    public const STORES = 'stores';

    // Blogs & Tech Journal
    public const BLOGS = 'blogs';

    public const BLOG_SHOW = 'blogs/{slug}';

    // Saved PC Builder
    public const PC_BUILDER_CHECK = 'pc-builder/check';

    public const PC_BUILDER_SAVE = 'pc-builder/save';

    public const PC_BUILDER_LOAD = 'pc-builder/load/{shareCode}';

    // Warranty & RMA
    public const WARRANTY_CHECK = 'warranty/check';

    public const WARRANTY_CLAIM = 'warranty/claim';

    // Site Settings
    public const SETTINGS = 'settings';

    // Web Public & Shop Routes
    public const WEB_HOME = '/';

    public const WEB_SHOP = '/shop';

    public const WEB_SHOP_CATEGORY = '/shop/{categorySlug}';

    public const WEB_PRODUCTS = '/products';

    public const WEB_PRODUCT_SHOW = '/products/{slug}';

    public const WEB_CART = '/cart';

    public const WEB_CHECKOUT = '/checkout';

    public const WEB_ORDER_SUCCESS = '/order/success';

    public const WEB_ABOUT = '/about';

    public const WEB_CONTACT = '/contact';

    /** How someone leaves the mailing list without signing in. */
    public const WEB_UNSUBSCRIBE = '/unsubscribe/{token}';

    public const CONTACT = 'contact';

    public const SUBSCRIBE = 'subscribe';

    public const ADMIN_MESSAGES = 'messages';

    public const ADMIN_MESSAGE_REPLY = 'messages/{id}/reply';

    public const ADMIN_MESSAGE_STATUS = 'messages/{id}/status';

    public const ADMIN_ORDER_PAYMENT = 'orders/{id}/payment';

    public const ADMIN_ORDER_CUSTOMERS = 'orders/customers';

    public const ADMIN_ORDER_LINES = 'orders/{id}/lines';

    public const ADMIN_CUSTOMER_ACTIVE = 'customers/{id}/active';

    public const ADMIN_STOCK_SERIALS = 'stock/serials';

    public const ADMIN_STOCK_SERIAL_ITEM = 'stock/serials/{id}';

    public const ADMIN_BARCODE_LOOKUP = 'stock/barcode';

    public const ADMIN_CAMPAIGNS = 'campaigns';

    public const ADMIN_CAMPAIGN_ITEM = 'campaigns/{id}';

    public const ADMIN_CAMPAIGN_PREVIEW = 'campaigns/preview';

    public const ADMIN_CAMPAIGN_PICKERS = 'campaigns/pickers';

    public const ADMIN_CAMPAIGN_SEND = 'campaigns/{id}/send';

    public const ADMIN_CAMPAIGN_RECIPIENTS = 'campaigns/{id}/recipients';

    public const ADMIN_PURCHASE_ORDERS = 'purchase-orders';

    public const ADMIN_PURCHASE_ORDER_ITEM = 'purchase-orders/{id}';

    public const ADMIN_PURCHASE_ORDER_SEND = 'purchase-orders/{id}/send';

    public const ADMIN_PURCHASE_ORDER_CANCEL = 'purchase-orders/{id}/cancel';

    public const ADMIN_PURCHASE_ORDER_RECEIVE = 'purchase-orders/{id}/receive';

    public const ADMIN_STOCK_COUNT = 'stock/count';

    public const ADMIN_STOCK_ADJUSTMENTS = 'stock/adjustments';

    public const ADMIN_ROLES = 'roles';

    public const ADMIN_ROLE_ITEM = 'roles/{id}';

    public const ADMIN_PAGES = 'pages';

    public const ADMIN_PAGE_ITEM = 'pages/{id}';

    public const ADMIN_SUBSCRIBERS = 'subscribers';

    public const ADMIN_SUBSCRIBER_ITEM = 'subscribers/{id}';

    public const WEB_TRACK = '/track';

    /**
     * The order number is in the URL so the page can be returned to and the
     * address bar says which order is on screen. It is not a key: the phone
     * number is still what proves the order is yours, and this only fills in
     * the first box.
     */
    public const WEB_TRACK_ONE = '/track/{orderNumber}';

    public const WEB_PC_BUILDER = '/pc-builder';

    public const WEB_PC_BUILDER_CHOOSE = '/pc-builder/choose/{categorySlug}';

    public const WEB_WISHLIST = '/wishlist';

    public const WEB_COMPARE = '/compare';

    public const WEB_STORES = '/stores';

    public const WEB_SUPPORT = '/support';

    public const WEB_OFFERS = '/offers';

    public const WEB_BLOGS = '/blogs';

    public const WEB_BLOG_SHOW = '/blogs/{slug}';

    public const WEB_WARRANTY = '/warranty';

    public const WEB_TRACK_ORDER = '/track-order';

    // Customer Account
    public const DASHBOARD = '/dashboard';

    /*
     * The account area is a page per section rather than one screen switching
     * tabs in the browser, so each has a URL that can be linked, bookmarked and
     * come back to.
     */
    public const DASHBOARD_ORDERS = '/dashboard/orders';

    public const DASHBOARD_WISHLIST = '/dashboard/wishlist';

    public const DASHBOARD_ADDRESSES = '/dashboard/addresses';

    public const DASHBOARD_PROFILE = '/dashboard/profile';

    public const ACCOUNT = '/account';

    public const ACCOUNT_PROFILE = '/account/profile';

    public const ACCOUNT_ADDRESS = '/account/address';

    public const ACCOUNT_ADDRESS_ITEM = '/account/address/{id}';

    public const ACCOUNT_PASSWORD = '/account/password';

    public const ACCOUNT_AVATAR = '/account/avatar';

    public const ACCOUNT_ORDER_CANCEL = '/account/orders/{id}/cancel';

    /** Printable invoice, for the customer and the admin alike. */
    public const ORDER_INVOICE = '/orders/{id}/invoice';

    // Admin
    public const ADMIN_PREFIX = 'admin';

    public const ADMIN_DASHBOARD = 'dashboard';

    public const ADMIN_ORDERS = 'orders';

    public const ADMIN_ORDERS_STATUS = 'orders/{id}/status';

    public const ADMIN_ORDERS_RETURN = 'orders/{id}/return';

    /** Handing a parcel to a carrier, with the number to chase it by. */
    public const ADMIN_ORDERS_DISPATCH = 'orders/{id}/dispatch';

    /** Money given back, recorded against the order it left. */
    public const ADMIN_REFUNDS = 'refunds';

    public const ADMIN_REFUNDS_ITEM = 'refunds/{id}';

    public const ADMIN_ORDERS_REFUND = 'orders/{id}/refund';

    /** Staff accounts and their roles. */
    public const ADMIN_STAFF = 'staff';

    public const ADMIN_STAFF_ITEM = 'staff/{id}';

    public const ADMIN_COURIERS = 'couriers';

    public const ADMIN_COURIERS_ITEM = 'couriers/{id}';

    public const ADMIN_COURIER_ZONES = 'couriers/{id}/zones';

    public const ADMIN_COURIER_ZONE_ITEM = 'couriers/{id}/zones/{zone}';

    // Stock: units enter through a receipt, are corrected by an audited
    // adjustment, and are never typed in as an absolute number.
    public const ADMIN_STOCK_RECEIPTS = 'stock/receipts';

    public const ADMIN_STOCK_ADJUST = 'stock/adjust';

    public const ADMIN_STOCK_MOVEMENTS = 'stock/products/{id}/movements';

    public const ADMIN_STOCK_UNITS = 'stock/units';

    public const ADMIN_STOCK_TRANSFER = 'stock/transfer';

    public const ADMIN_STOCK_BRANCHES = 'stock/products/{id}/branches';

    // Suppliers are their own section, not part of the stock screen.
    public const ADMIN_SUPPLIERS = 'suppliers';

    public const ADMIN_SUPPLIERS_ITEM = 'suppliers/{id}';

    /** Just the list, for the delivery form's dropdown. */
    public const ADMIN_SUPPLIER_OPTIONS = 'suppliers/options';

    public const ADMIN_PRODUCTS = 'products';

    public const ADMIN_PRODUCTS_ITEM = 'products/{id}';

    public const ADMIN_CATEGORIES = 'categories';

    public const ADMIN_CATEGORIES_ITEM = 'categories/{id}';

    public const ADMIN_BANNERS = 'banners';

    public const ADMIN_BANNERS_ITEM = 'banners/{id}';

    public const ADMIN_COUPONS = 'coupons';

    public const ADMIN_COUPONS_ITEM = 'coupons/{id}';

    public const ADMIN_STORES = 'stores';

    public const ADMIN_STORES_ITEM = 'stores/{id}';

    public const ADMIN_SETTINGS = 'settings';

    public const ADMIN_CUSTOMERS = 'customers';

    // Running costs, and the statement built from them.
    public const ADMIN_EXPENSES = 'expenses';

    public const ADMIN_EXPENSES_ITEM = 'expenses/{id}';

    public const ADMIN_EXPENSE_CATEGORIES = 'expense-categories';

    public const ADMIN_EXPENSE_CATEGORIES_ITEM = 'expense-categories/{id}';

    public const ADMIN_REPORTS = 'reports';

    public const ADMIN_REPORTS_SALES = 'reports/sales';

    public const ADMIN_REPORTS_STOCK = 'reports/stock';

    public const ADMIN_REPORTS_MONEY = 'reports/money';

    public const ADMIN_REPORTS_DELIVERY = 'reports/delivery';

    public const ADMIN_REPORTS_SUPPLIERS = 'reports/suppliers';

    public const ADMIN_REPORTS_PROFIT = 'reports/profit-loss';

    public const ADMIN_BLOGS = 'blogs';

    public const ADMIN_BLOGS_ITEM = 'blogs/{id}';

    public const ADMIN_MEDIA = 'media';

    public const ADMIN_SETTINGS_TEST_EMAIL = 'settings/test-email';

    public const ADMIN_REVIEWS = 'reviews';

    public const ADMIN_REVIEWS_ITEM = 'reviews/{id}';

    public const ADMIN_REVIEWS_STATUS = 'reviews/{id}/status';

    public const ADMIN_WARRANTY = 'warranty';

    public const ADMIN_WARRANTY_STATUS = 'warranty/{id}/status';

    // Laravel Auth Profile
    public const WEB_PROFILE = '/profile';
}
