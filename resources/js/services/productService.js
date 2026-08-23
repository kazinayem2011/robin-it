import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

/**
 * Product API Service (SSOT)
 * Endpoints are centrally controlled from constants/endpoints.js
 */
export const productService = {
    /**
     * Get paginated products with optional filters.
     *
     * The API returns rows in `data` and paging info in `meta`; this normalises
     * that into { items, meta } so pages never poke at `data.data`.
     *
     * @param {Object} params - { category_slug, brand_slug, search, sort, is_featured, in_stock, page, per_page }
     * @returns {Promise<{items: Array, meta: Object}>}
     */
    /**
     * The price range and brands present in a selection, for the filter panel.
     */
    async getFilters(params = {}) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.PRODUCTS.FILTERS,
            { params },
        );

        return response?.data || null;
    },

    async getProducts(params = {}) {
        const response = await axiosInstance.get(API_ENDPOINTS.PRODUCTS.LIST, {
            params,
        });

        return {
            items: Array.isArray(response?.data) ? response.data : [],
            meta: response?.meta || {
                current_page: 1,
                last_page: 1,
                total: 0,
            },
        };
    },

    /**
     * Get single product details by slug.
     * @param {string} slug
     */
    async getProductBySlug(slug) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.PRODUCTS.DETAIL(slug),
        );
        return response?.data || response;
    },

    /**
     * Get Flash Sale products.
     */
    async getFlashSale() {
        const response = await axiosInstance.get(
            API_ENDPOINTS.PRODUCTS.FLASH_SALE,
        );
        return response?.data || response || [];
    },

    /**
     * Get featured products by category tab.
     * @param {string} tab - 'all' | 'laptops' | 'desktop' | 'gpu' | 'monitors'
     */
    async getFeatured(tab = 'all') {
        const response = await axiosInstance.get(
            API_ENDPOINTS.PRODUCTS.FEATURED,
            { params: { tab } },
        );
        return response?.data || response || [];
    },

    /**
     * Get live dynamic specs for PC Builder Mini widget.
     */
    async getBuilderQuickSpecs() {
        const response = await axiosInstance.get(
            API_ENDPOINTS.PRODUCTS.BUILDER_SPECS,
        );
        return response?.data || response || null;
    },

    /**
     * Get live instant search suggestions (Products, Categories, Brands).
     * @param {string} query
     */
    async getSearchSuggestions(query) {
        if (!query || query.trim().length < 2) {
            return { products: [], categories: [], brands: [] };
        }
        const response = await axiosInstance.get(
            API_ENDPOINTS.PRODUCTS.SUGGESTIONS,
            { params: { q: query.trim() } },
        );
        return response?.data || { products: [], categories: [], brands: [] };
    },
};

export default productService;
