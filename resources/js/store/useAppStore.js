import { create } from 'zustand';
import { cartService } from '../services';

const useAppStore = create((set, get) => ({
    // Client UI State
    isMobileMenuOpen: false,
    toggleMobileMenu: () =>
        set((state) => ({ isMobileMenuOpen: !state.isMobileMenuOpen })),

    isCartSidebarOpen: false,
    toggleCartSidebar: () =>
        set((state) => ({ isCartSidebarOpen: !state.isCartSidebarOpen })),

    // Realtime Badges & Counts
    cartCount: 0,
    setCartCount: (count) => set({ cartCount: count }),
    fetchCartCount: async () => {
        try {
            const cart = await cartService.getCart();
            if (cart && cart.items) {
                const totalQty = cart.items.reduce(
                    (sum, item) => sum + item.quantity,
                    0,
                );
                set({ cartCount: totalQty });
            }
        } catch (error) {
            console.error('Failed to fetch cart count', error);
        }
    },

    wishlistCount: 0,
    setWishlistCount: (count) => set({ wishlistCount: count }),

    compareCount: 0,
    setCompareCount: (count) => set({ compareCount: count }),

    // Toast Notifications System
    toasts: [],
    addToast: ({
        type = 'success',
        title = '',
        message = '',
        duration = 4000,
    }) => {
        const id = Date.now() + Math.random().toString(36).substring(2, 9);
        const newToast = { id, type, title, message, duration };

        set((state) => ({ toasts: [...state.toasts, newToast] }));

        if (duration > 0) {
            setTimeout(() => {
                get().removeToast(id);
            }, duration);
        }
        return id;
    },
    removeToast: (id) =>
        set((state) => ({
            toasts: state.toasts.filter((toast) => toast.id !== id),
        })),

    // Client-side transient data (e.g., PC Builder)
    pcBuilderItems: [],
    addPcBuilderItem: (item) =>
        set((state) => ({ pcBuilderItems: [...state.pcBuilderItems, item] })),
    /**
     * Set (or replace) the product occupying one builder slot.
     * PcBuilder/Index called this when opening a shared build; it was never
     * defined, so loading a shared link threw instead of restoring the rig.
     */
    setPcBuilderItem: (componentId, product) =>
        set((state) => ({
            pcBuilderItems: [
                ...state.pcBuilderItems.filter(
                    (item) => item.componentId !== componentId,
                ),
                { id: product.id, componentId, product },
            ],
        })),
    /**
     * Empty one slot. Keyed by componentId, not by product id: the builder has
     * only ever passed the slot it wants cleared, so filtering on `id` compared
     * a slug against a product id, matched nothing, and left Remove doing
     * nothing at all.
     */
    removePcBuilderItem: (componentId) =>
        set((state) => ({
            pcBuilderItems: state.pcBuilderItems.filter(
                (item) => item.componentId !== componentId,
            ),
        })),
    clearPcBuilder: () => set({ pcBuilderItems: [] }),
}));

export default useAppStore;
