import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const reviewService = {
    async getProductReviews(productSlug) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.REVIEWS.LIST(productSlug),
        );
        return (
            response.data?.data || {
                average_rating: 5.0,
                total_reviews: 0,
                breakdown: {},
                reviews: [],
            }
        );
    },

    async submitReview(productSlug, reviewData) {
        const response = await axiosInstance.post(
            API_ENDPOINTS.REVIEWS.SUBMIT(productSlug),
            reviewData,
        );
        return response.data;
    },
};

export default reviewService;
