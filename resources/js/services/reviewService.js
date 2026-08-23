import axiosInstance from './axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';

export const reviewService = {
    async getProductReviews(productSlug) {
        const response = await axiosInstance.get(
            API_ENDPOINTS.REVIEWS.LIST(productSlug),
        );
        // See note in storeService: the payload is response.data, not
        // response.data.data — reviews always fell back to this empty shape.
        return (
            response?.data || {
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
        return response?.data || response;
    },
};

export default reviewService;
