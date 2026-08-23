import axiosInstance from './axiosInstance';

export const bannerService = {
    async getBanners(position = null) {
        const url = position ? `/banners?position=${position}` : '/banners';
        const response = await axiosInstance.get(url);
        // axiosInstance already unwraps to the response envelope, so the payload
        // is response.data. Reading response.data.data went one level too deep
        // and always fell through to the empty fallback.
        return response?.data || [];
    },

    async getHeroBanners() {
        return this.getBanners('hero');
    },

    async getPromoSideBanners() {
        return this.getBanners('promo_side');
    },
};

export default bannerService;
