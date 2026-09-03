/*
 * Every method here returns the response envelope, not the payload inside it.
 *
 * The axios instance already unwraps one level — its interceptor returns
 * response.data, which is { error, code, message, data, meta } — so the
 * `response?.data || response` these all used unwrapped a second time and
 * threw the message away. Twenty-nine methods did it, and the effect was that
 * the backend wrote a careful sentence ("Received. 6 still outstanding on
 * PO-20260829-001.") and the interface showed whichever generic fallback the
 * calling component happened to have.
 *
 * Callers read `.message` for the sentence and `.data` for the payload.
 */
import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * Admin API Service (SSOT)
 * Centralized service for all executive management operations.
 */
export const adminService = {
    /**
     * Update order shipment and processing status.
     * @param {number|string} orderId
     * @param {string} status - 'pending' | 'processing' | 'shipped' | 'delivered' | 'cancelled'
     */
    async updateOrderStatus(orderId, status) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.ORDER_STATUS(orderId),
            { status },
        );
        return response;
    },

    /**
     * Create a new product in the catalog.
     * @param {Object} productData
     */
    async createProduct(productData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PRODUCTS,
            productData,
        );
        return response;
    },

    /**
     * Update product price, stock quantity, and flags.
     * @param {number|string} productId
     * @param {Object} productData
     */
    async updateProduct(productId, productData) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.PRODUCT_ITEM(productId),
            productData,
        );
        return response;
    },

    // ── Inventory ────────────────────────────────────────────────────────────
    // There is deliberately no "set stock to N" call. Units enter through a
    // receipt, leave through an order, and are corrected only by an adjustment
    // that states a reason — so the ledger always explains the balance.

    /**
     * Book a delivery from a supplier.
     * @param {Object} payload - { supplier_name, invoice_number, received_on, note, lines[] }
     */
    async receiveStock(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.STOCK_RECEIPTS,
            payload,
        );
        return response;
    },

    // ── Running costs ────────────────────────────────────────────────────────
    // Rent, wages, the courier's bill. Buying stock is deliberately not one of
    // these: units are inventory until they sell, and reach the accounts as
    // cost of goods sold on the order that sells them.

    async createExpense(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.EXPENSES,
            payload,
        );
        return response;
    },

    async updateExpense(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.EXPENSE_ITEM(id),
            payload,
        );
        return response;
    },

    async deleteExpense(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.EXPENSE_ITEM(id),
        );
        return response;
    },

    /** Hand a parcel to a carrier, with the number to chase it by. */
    async dispatchOrder(orderId, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.ORDER_DISPATCH(orderId),
            payload,
        );
        return response;
    },

    /** Record money given back on an order. */
    async refundOrder(orderId, payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.ORDER_REFUND(orderId),
            payload,
        );
        return response;
    },

    async deleteRefund(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.REFUND_ITEM(id),
        );
        return response;
    },

    async createStaff(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.STAFF,
            payload,
        );
        return response;
    },

    async updateStaff(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.STAFF_ITEM(id),
            payload,
        );
        return response;
    },

    async suspendStaff(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.STAFF_ITEM(id),
        );
        return response;
    },

    async createCourier(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.COURIERS,
            payload,
        );
        return response;
    },

    async updateCourier(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.COURIER_ITEM(id),
            payload,
        );
        return response;
    },

    async deleteCourier(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.COURIER_ITEM(id),
        );
        return response;
    },

    async createExpenseCategory(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.EXPENSE_CATEGORIES,
            payload,
        );
        return response;
    },

    async updateExpenseCategory(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.EXPENSE_CATEGORY_ITEM(id),
            payload,
        );
        return response;
    },

    async deleteExpenseCategory(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.EXPENSE_CATEGORY_ITEM(id),
        );
        return response;
    },

    /** Suppliers, for the delivery form's dropdown. */
    async getSuppliers() {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.SUPPLIERS);
        return response;
    },

    async createSupplier(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.SUPPLIERS,
            payload,
        );
        return response;
    },

    async updateSupplier(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.SUPPLIER_ITEM(id),
            payload,
        );
        return response;
    },

    async deleteSupplier(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.SUPPLIER_ITEM(id),
        );
        return response;
    },

    /** Past deliveries. */
    async getStockReceipts(params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.STOCK_RECEIPTS,
            { params },
        );
        return response;
    },

    /**
     * Correct a count: breakage, loss, or a stock-take that disagrees.
     * @param {Object} payload - { product_id, product_variant_id, quantity, reason, note }
     */
    async adjustStock(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.STOCK_ADJUST,
            payload,
        );
        return response;
    },

    /**
     * Move units between branches. Nets to zero — this changes where stock is,
     * never how much there is.
     */
    async transferStock(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.STOCK_TRANSFER,
            payload,
        );
        return response;
    },

    /** What each branch holds of one product or option. */
    async getStockBranches(productId, variantId = null) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.STOCK_BRANCHES(productId),
            { params: variantId ? { variant_id: variantId } : {} },
        );
        return response;
    },

    /** The ledger for one product, newest first. */
    async getStockMovements(productId, params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.STOCK_MOVEMENTS(productId),
            { params },
        );
        return response;
    },

    /**
     * Take an order at the counter or over the phone.
     *
     * Goes through the same OrderService the storefront uses, so the stock
     * check, the reservation and the coupon rules are the ones already known
     * to work rather than a second set written for the counter.
     */
    async createOrder(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.ORDERS,
            payload,
        );
        return response;
    },

    /** Customers an order can be attached to, matched on what a caller says. */
    async searchOrderCustomers(search) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.ORDER_CUSTOMERS,
            { params: { search } },
        );
        return response;
    },

    /**
     * Change what is on an order after it was placed.
     *
     * The whole line list, not a patch: stock is already held against every
     * line, so the server settles the difference and needs to see the shape
     * the order should end up in.
     */
    async updateOrderLines(orderId, payload) {
        const response = await axiosInstance.put(
            API_ENDPOINTS.ADMIN.ORDER_LINES(orderId),
            payload,
        );
        return response;
    },

    /**
     * Suspend a customer, or let them back in.
     *
     * Suspending keeps the account and its orders and closes the door; the
     * alternative was deleting them, which loses the history you most want
     * when the reason for stopping them is a dispute.
     */
    async setCustomerActive(id, active) {
        const response = await axiosInstance.put(
            API_ENDPOINTS.ADMIN.CUSTOMER_ACTIVE(id),
            { is_active: active },
        );
        return response;
    },

    /** Who a campaign would reach, and what the texts would cost. Sends nothing. */
    async previewCampaign(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.CAMPAIGN_PREVIEW,
            payload,
        );
        return response;
    },

    /** Products and coupons to drop into a campaign. */
    async getCampaignPickers(params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.CAMPAIGN_PICKERS,
            { params },
        );
        return response;
    },

    /** Who got a campaign, who did not, and why not. */
    async getCampaignRecipients(id, params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.CAMPAIGN_RECIPIENTS(id),
            { params },
        );
        return response;
    },

    async createCampaign(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.CAMPAIGNS,
            payload,
        );
        return response;
    },

    /** Set it going. Queued: it cannot be recalled once started. */
    async sendCampaign(id) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.CAMPAIGN_SEND(id),
        );
        return response;
    },

    async deleteCampaign(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.CAMPAIGN(id),
        );
        return response;
    },

    /** Ask a supplier for stock. Saved as a draft until it is sent. */
    async createPurchaseOrder(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDERS,
            payload,
        );
        return response;
    },

    /** Correct a draft: its lines, their quantities and what they cost. */
    async updatePurchaseOrder(id, payload) {
        const response = await axiosInstance.put(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDER_ITEM(id),
            payload,
        );
        return response;
    },

    async sendPurchaseOrder(id) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDER_SEND(id),
        );
        return response;
    },

    async cancelPurchaseOrder(id) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDER_CANCEL(id),
        );
        return response;
    },

    /** Book in what actually arrived; anything short stays outstanding. */
    async receivePurchaseOrder(id, payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDER_RECEIVE(id),
            payload,
        );
        return response;
    },

    /**
     * What was just scanned.
     *
     * A handheld scanner is a keyboard: it types the code and presses Enter.
     * There is no camera to drive — the whole job is turning that string into
     * a product, a variant, or the serial of one unit.
     */
    async scanBarcode(code) {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.BARCODE, {
            params: { code },
        });
        return response;
    },

    /**
     * Map a city or thana to a courier's own area ids.
     *
     * Pathao and RedX book against numbers from their own lists, not against a
     * written address. Without a mapping every parcel uses the one default
     * saved with the credentials.
     */
    async mapCourierZone(courierId, payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.COURIER_ZONES(courierId),
            payload,
        );
        return response;
    },

    async unmapCourierZone(courierId, zoneId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.COURIER_ZONE(courierId, zoneId),
        );
        return response;
    },

    /**
     * Record serials against stock already on the shelf.
     *
     * Serials normally arrive with a delivery. This is for the shop that
     * started tracking them part way through, and for the delivery received
     * before anyone had time to open the boxes.
     */
    async addSerials(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.STOCK_SERIALS,
            payload,
        );
        return response;
    },

    /** Fix a serial that was typed wrong, on a unit sold or not. */
    async correctSerial(id, payload) {
        const response = await axiosInstance.put(
            API_ENDPOINTS.ADMIN.STOCK_SERIAL(id),
            payload,
        );
        return response;
    },

    /** Delete a serial recorded in error. Refused once the unit is sold. */
    async removeSerial(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.STOCK_SERIAL(id),
        );
        return response;
    },

    /** Products and options that can hold stock, for the pickers. */
    async getStockUnits(params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.STOCK_UNITS,
            { params },
        );
        return response;
    },

    /**
     * Take back a delivered order, item by item.
     * @param {number|string} orderId
     * @param {Object} payload - { note, lines: [{ order_item_id, resellable, damaged }] }
     */
    async returnOrder(orderId, payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.ORDER_RETURN(orderId),
            payload,
        );
        return response;
    },

    /**
     * Fetch executive dashboard metrics.
     */
    async getDashboardMetrics() {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.DASHBOARD);
        return response;
    },

    /**
     * Fetch orders with optional filters.
     * @param {Object} params - { status, search, page }
     */
    async getOrders(params = {}) {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.ORDERS, {
            params,
        });
        return response;
    },

    /**
     * Fetch products list for inventory management.
     * @param {Object} params - { search, category_id, page }
     */
    async getProducts(params = {}) {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.PRODUCTS, {
            params,
        });
        return response;
    },

    /**
     * Fetch customer directory list.
     * @param {Object} params - { search, page }
     */
    async getCustomers(params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.CUSTOMERS,
            { params },
        );
        return response;
    },

    /**
     * Create a new category / subcategory in the taxonomy.
     * @param {Object} categoryData
     */
    async createCategory(categoryData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.CATEGORIES,
            categoryData,
        );
        return response;
    },

    /**
     * Update an existing category in the hierarchy.
     * @param {number|string} categoryId
     * @param {Object} categoryData
     */
    async updateCategory(categoryId, categoryData) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.CATEGORY_ITEM(categoryId),
            categoryData,
        );
        return response;
    },

    /**
     * Delete a category from the database.
     * @param {number|string} categoryId
     */
    async deleteCategory(categoryId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.CATEGORY_ITEM(categoryId),
        );
        return response;
    },

    /**
     * Create a new marketing banner / hero slider.
     * @param {Object} bannerData
     */
    async createBanner(bannerData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.BANNERS,
            bannerData,
        );
        return response;
    },

    /**
     * Update an existing banner.
     * @param {number|string} bannerId
     * @param {Object} bannerData
     */
    async updateBanner(bannerId, bannerData) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.BANNER_ITEM(bannerId),
            bannerData,
        );
        return response;
    },

    /**
     * Delete a banner.
     * @param {number|string} bannerId
     */
    async deleteBanner(bannerId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.BANNER_ITEM(bannerId),
        );
        return response;
    },

    /**
     * Create a new promo coupon.
     * @param {Object} couponData
     */
    async createCoupon(couponData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.COUPONS,
            couponData,
        );
        return response;
    },

    /**
     * Update an existing promo coupon.
     * @param {number|string} couponId
     * @param {Object} couponData
     */
    async updateCoupon(couponId, couponData) {
        const response = await axiosInstance.put(
            API_ENDPOINTS.ADMIN.COUPON_ITEM(couponId),
            couponData,
        );
        return response;
    },

    /**
     * Delete a coupon.
     * @param {number|string} couponId
     */
    async deleteCoupon(couponId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.COUPON_ITEM(couponId),
        );
        return response;
    },

    /**
     * Create a showroom branch.
     * @param {Object} storeData
     */
    async createStore(storeData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.STORES,
            storeData,
        );
        return response;
    },

    /**
     * Update an existing showroom branch.
     * @param {number|string} storeId
     * @param {Object} storeData
     */
    async updateStore(storeId, storeData) {
        const response = await axiosInstance.put(
            API_ENDPOINTS.ADMIN.STORE_ITEM(storeId),
            storeData,
        );
        return response;
    },

    /**
     * Delete a showroom branch.
     * @param {number|string} storeId
     */
    async deleteStore(storeId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.STORE_ITEM(storeId),
        );
        return response;
    },

    /**
     * Save global site settings.
     * @param {Object} settingsData
     */
    /**
     * Send a test message using the SMTP settings currently saved.
     * Rejects with the real SMTP error so the admin can act on it.
     */
    async sendTestEmail(email) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.SETTINGS_TEST_EMAIL,
            { email },
        );
        return response;
    },

    async updateSettings(settingsData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.SETTINGS,
            { settings: settingsData },
        );
        return response;
    },

    // --- Offers: the campaigns the shop runs -----------------------------
    // Not the discounted listing, which is worked out from product prices and
    // needs no manager.

    async createOffer(offerData) {
        return axiosInstance.post(API_ENDPOINTS.ADMIN.OFFERS, offerData);
    },

    async updateOffer(offerId, offerData) {
        return axiosInstance.put(
            API_ENDPOINTS.ADMIN.OFFER_ITEM(offerId),
            offerData,
        );
    },

    async deleteOffer(offerId) {
        return axiosInstance.delete(API_ENDPOINTS.ADMIN.OFFER_ITEM(offerId));
    },

    /**
     * Create a new Tech Journal / Blog post.
     * @param {Object} blogData
     */
    async createBlog(blogData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.BLOGS,
            blogData,
        );
        return response;
    },

    /**
     * Update an existing Tech Journal / Blog post.
     * @param {number|string} blogId
     * @param {Object} blogData
     */
    async updateBlog(blogId, blogData) {
        const response = await axiosInstance.put(
            API_ENDPOINTS.ADMIN.BLOG_ITEM(blogId),
            blogData,
        );
        return response;
    },

    /**
     * Delete a Tech Journal / Blog post.
     * @param {number|string} blogId
     */
    async deleteBlog(blogId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.BLOG_ITEM(blogId),
        );
        return response;
    },

    // --- Contact inbox -------------------------------------------------

    async replyToMessage(id, body, close = false) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.MESSAGE_REPLY(id),
            { body, close },
        );
        return response;
    },

    async setMessageStatus(id, status) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.MESSAGE_STATUS(id),
            { status },
        );
        return response;
    },

    /**
     * Turn emails to an address on or off. Nothing here deletes a subscriber:
     * a deleted row is added back by the next import as though they had never
     * asked to be left alone.
     */
    async setSubscriberActive(id, active) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.SUBSCRIBER_ITEM(id),
            { active },
        );
        return response;
    },

    // --- Content pages -------------------------------------------------

    async createPage(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PAGES,
            payload,
        );
        return response;
    },

    async updatePage(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.PAGE_ITEM(id),
            payload,
        );
        return response;
    },

    async deletePage(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.PAGE_ITEM(id),
        );
        return response;
    },

    // --- Roles ---------------------------------------------------------

    async createRole(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.ROLES,
            payload,
        );
        return response;
    },

    async updateRole(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.ROLE_ITEM(id),
            payload,
        );
        return response;
    },

    async deleteRole(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.ROLE_ITEM(id),
        );
        return response;
    },

    // --- Counting the shelves -------------------------------------------

    /**
     * Apply a count. All or nothing: a count half-applied is worse than one
     * not applied at all, because nobody can tell which half is real.
     */
    async applyStockCount(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.STOCK_COUNT,
            payload,
        );
        return response;
    },

    /** Record money received against an order — a deposit, or the balance. */
    async recordOrderPayment(id, payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.ORDER_PAYMENT(id),
            payload,
        );
        return response;
    },
};

export default adminService;
