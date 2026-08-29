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
        return response.data;
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
        return response.data;
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
        return response.data;
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
        return response.data;
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
        return response.data;
    },

    async updateExpense(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.EXPENSE_ITEM(id),
            payload,
        );
        return response.data;
    },

    async deleteExpense(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.EXPENSE_ITEM(id),
        );
        return response.data;
    },

    /** Hand a parcel to a carrier, with the number to chase it by. */
    async dispatchOrder(orderId, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.ORDER_DISPATCH(orderId),
            payload,
        );
        return response.data;
    },

    /** Record money given back on an order. */
    async refundOrder(orderId, payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.ORDER_REFUND(orderId),
            payload,
        );
        return response.data;
    },

    async deleteRefund(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.REFUND_ITEM(id),
        );
        return response.data;
    },

    async createStaff(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.STAFF,
            payload,
        );
        return response.data;
    },

    async updateStaff(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.STAFF_ITEM(id),
            payload,
        );
        return response.data;
    },

    async suspendStaff(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.STAFF_ITEM(id),
        );
        return response.data;
    },

    async createCourier(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.COURIERS,
            payload,
        );
        return response.data;
    },

    async updateCourier(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.COURIER_ITEM(id),
            payload,
        );
        return response.data;
    },

    async deleteCourier(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.COURIER_ITEM(id),
        );
        return response.data;
    },

    async createExpenseCategory(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.EXPENSE_CATEGORIES,
            payload,
        );
        return response.data;
    },

    async updateExpenseCategory(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.EXPENSE_CATEGORY_ITEM(id),
            payload,
        );
        return response.data;
    },

    async deleteExpenseCategory(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.EXPENSE_CATEGORY_ITEM(id),
        );
        return response.data;
    },

    /** Suppliers, for the delivery form's dropdown. */
    async getSuppliers() {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.SUPPLIERS);
        return response.data;
    },

    async createSupplier(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.SUPPLIERS,
            payload,
        );
        return response.data;
    },

    async updateSupplier(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.SUPPLIER_ITEM(id),
            payload,
        );
        return response.data;
    },

    async deleteSupplier(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.SUPPLIER_ITEM(id),
        );
        return response.data;
    },

    /** Past deliveries. */
    async getStockReceipts(params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.STOCK_RECEIPTS,
            { params },
        );
        return response.data;
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
        return response.data;
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
        return response.data;
    },

    /** What each branch holds of one product or option. */
    async getStockBranches(productId, variantId = null) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.STOCK_BRANCHES(productId),
            { params: variantId ? { variant_id: variantId } : {} },
        );
        return response.data;
    },

    /** The ledger for one product, newest first. */
    async getStockMovements(productId, params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.STOCK_MOVEMENTS(productId),
            { params },
        );
        return response.data;
    },

    /** Ask a supplier for stock. Saved as a draft until it is sent. */
    async createPurchaseOrder(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDERS,
            payload,
        );
        return response?.data || response;
    },

    async sendPurchaseOrder(id) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDER_SEND(id),
        );
        return response?.data || response;
    },

    async cancelPurchaseOrder(id) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDER_CANCEL(id),
        );
        return response?.data || response;
    },

    /** Book in what actually arrived; anything short stays outstanding. */
    async receivePurchaseOrder(id, payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PURCHASE_ORDER_RECEIVE(id),
            payload,
        );
        return response?.data || response;
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
        return response?.data || response;
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
        return response?.data || response;
    },

    async unmapCourierZone(courierId, zoneId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.COURIER_ZONE(courierId, zoneId),
        );
        return response?.data || response;
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
        return response?.data || response;
    },

    /** Fix a serial that was typed wrong, on a unit sold or not. */
    async correctSerial(id, payload) {
        const response = await axiosInstance.put(
            API_ENDPOINTS.ADMIN.STOCK_SERIAL(id),
            payload,
        );
        return response?.data || response;
    },

    /** Delete a serial recorded in error. Refused once the unit is sold. */
    async removeSerial(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.STOCK_SERIAL(id),
        );
        return response?.data || response;
    },

    /** Products and options that can hold stock, for the pickers. */
    async getStockUnits(params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.ADMIN.STOCK_UNITS,
            { params },
        );
        return response.data;
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
        return response.data;
    },

    /**
     * Fetch executive dashboard metrics.
     */
    async getDashboardMetrics() {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.DASHBOARD);
        return response.data;
    },

    /**
     * Fetch orders with optional filters.
     * @param {Object} params - { status, search, page }
     */
    async getOrders(params = {}) {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.ORDERS, {
            params,
        });
        return response.data;
    },

    /**
     * Fetch products list for inventory management.
     * @param {Object} params - { search, category_id, page }
     */
    async getProducts(params = {}) {
        const response = await axiosInstance.get(API_ENDPOINTS.ADMIN.PRODUCTS, {
            params,
        });
        return response.data;
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
        return response.data;
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
        return response.data;
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
        return response.data;
    },

    /**
     * Delete a category from the database.
     * @param {number|string} categoryId
     */
    async deleteCategory(categoryId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.CATEGORY_ITEM(categoryId),
        );
        return response.data;
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
        return response.data;
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
        return response.data;
    },

    /**
     * Delete a banner.
     * @param {number|string} bannerId
     */
    async deleteBanner(bannerId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.BANNER_ITEM(bannerId),
        );
        return response.data;
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
        return response.data;
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
        return response.data;
    },

    /**
     * Delete a coupon.
     * @param {number|string} couponId
     */
    async deleteCoupon(couponId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.COUPON_ITEM(couponId),
        );
        return response.data;
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
        return response.data;
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
        return response.data;
    },

    /**
     * Delete a showroom branch.
     * @param {number|string} storeId
     */
    async deleteStore(storeId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.STORE_ITEM(storeId),
        );
        return response.data;
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
        return response.data;
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
        return response.data;
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
        return response.data;
    },

    /**
     * Delete a Tech Journal / Blog post.
     * @param {number|string} blogId
     */
    async deleteBlog(blogId) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.BLOG_ITEM(blogId),
        );
        return response.data;
    },

    // --- Contact inbox -------------------------------------------------

    async replyToMessage(id, body, close = false) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.MESSAGE_REPLY(id),
            { body, close },
        );
        return response?.data || response;
    },

    async setMessageStatus(id, status) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.MESSAGE_STATUS(id),
            { status },
        );
        return response?.data || response;
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
        return response?.data || response;
    },

    // --- Content pages -------------------------------------------------

    async createPage(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.PAGES,
            payload,
        );
        return response?.data || response;
    },

    async updatePage(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.PAGE_ITEM(id),
            payload,
        );
        return response?.data || response;
    },

    async deletePage(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.PAGE_ITEM(id),
        );
        return response?.data || response;
    },

    // --- Roles ---------------------------------------------------------

    async createRole(payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.ROLES,
            payload,
        );
        return response?.data || response;
    },

    async updateRole(id, payload) {
        const response = await axiosInstance.patch(
            API_ENDPOINTS.ADMIN.ROLE_ITEM(id),
            payload,
        );
        return response?.data || response;
    },

    async deleteRole(id) {
        const response = await axiosInstance.delete(
            API_ENDPOINTS.ADMIN.ROLE_ITEM(id),
        );
        return response?.data || response;
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
        return response?.data || response;
    },

    /** Record money received against an order — a deposit, or the balance. */
    async recordOrderPayment(id, payload) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.ADMIN.ORDER_PAYMENT(id),
            payload,
        );
        return response?.data || response;
    },
};

export default adminService;
