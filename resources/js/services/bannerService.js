import axiosInstance from './axiosInstance';

export const bannerService = {
    async getBanners(position = null) {
        const url = position ? `/banners?position=${position}` : '/banners';
        const response = await axiosInstance.get(url);
        return response.data?.data || [];
    },

    async getHeroBanners() {
        return this.getBanners('hero');
    },

    async getPromoSideBanners() {
        return this.getBanners('promo_side');
    },
};

export default bannerService;
