import axiosInstance from './axiosInstance';

export const storeService = {
    async getStores() {
        const response = await axiosInstance.get('/stores');
        return response.data?.data || [];
    },
};

export default storeService;
