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
};

export default adminService;
