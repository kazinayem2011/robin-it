import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const blogService = {
    /**
     * Get published blog posts / articles (SSOT)
     */
    async getBlogs(params = {}) {
        const response = await axiosInstance.get(API_ENDPOINTS.BLOGS.LIST, {
            params,
        });
        return response.data?.data || [];
    },

    /**
     * Get single article by slug (SSOT)
     */
    async getBlogBySlug(slug) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.BLOGS.DETAIL(slug),
        );
        return response.data?.data || null;
    },
};

export default blogService;
