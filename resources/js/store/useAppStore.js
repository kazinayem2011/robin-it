import { create } from 'zustand';
import { cartService, categoryService, compareService } from '../services';
import { readCachedMenu, writeCachedMenu } from '../utils/menuCache';
import { applyTheme, readThemeChoice, writeThemeChoice } from '../utils/theme';

const useAppStore = create((set, get) => ({
    // Client UI State

    /*
     * The theme, held here rather than in each toggle's own state.
     *
     * Three copies of the control can be on one page — the top bar, the
     * footer, and the drawer on a phone — and they must not be able to
     * disagree about what is showing. Held in the store they read one value
     * and any write repaints all of them.
     *
     * The initial value comes from the same reader the inline script in
     * app.blade.php used, so the store agrees with the attribute already on
     * <html> and the first render does not repaint the page it was given.
     */
    theme: readThemeChoice(),
    setTheme: (choice) => {
        writeThemeChoice(choice);

        // applyTheme both stamps <html> and hands back what was applied, so
        // the store cannot drift from the document.
        set({ theme: applyTheme(choice) });
    },
    toggleTheme: () =>
        get().setTheme(get().theme === 'dark' ? 'light' : 'dark'),

    isMobileMenuOpen: false,
    toggleMobileMenu: () =>
        set((state) => ({ isMobileMenuOpen: !state.isMobileMenuOpen })),

    isCartSidebarOpen: false,
    toggleCartSidebar: () =>
        set((state) => ({ isCartSidebarOpen: !state.isCartSidebarOpen })),

    /*
     * The category tree, held once for the whole page.
     *
     * The header fetched this into its own state, which left every other part
     * of the site that wanted to link to a category writing the slugs out by
     * hand — and the footer's five were written against a taxonomy the shop
     * had already replaced, so all five had been landing on an empty grid.
     * Anything that reads from here cannot name a category that is not there.
     */
    categories: readCachedMenu(),
    categoriesLoaded: false,
    fetchCategories: async () => {
        // The header and the footer both want this on the same page; the
        // first one through does the work.
        if (get().categoriesLoaded) return;
        set({ categoriesLoaded: true });

        try {
            const data = await categoryService.getMegaMenu();

            if (Array.isArray(data) && data.length) {
                set({ categories: data });
                writeCachedMenu(data);
            }
        } catch (error) {
            // Whatever the cache gave us stays: a stale menu beats no menu.
            set({ categoriesLoaded: false });
            console.error('Mega menu API load error:', error);
        }
    },

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
    /*
     * The compare badge only ever knew what happened in this page's lifetime.
     *
     * It was set when something was added and when the compare page was
     * opened, and nowhere else — so it started every page at nought. Refresh
     * with four things in the matrix and the badge simply vanished, until you
     * added a fifth or opened the page it was counting.
     *
     * The cart has been asking the server on boot all along; this is the same,
     * for the same reason.
     */
    fetchCompareCount: async () => {
        try {
            const items = await compareService.getComparison();

            set({ compareCount: Array.isArray(items) ? items.length : 0 });
        } catch (error) {
            console.error('Failed to fetch compare count', error);
        }
    },

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

    /*
     * Choosing an option without leaving the aisle.
     *
     * A product sold by option cannot be added from a card — the server needs
     * to know which one — and the card used to answer that by navigating to
     * the product page, which loses the shopper's place in a list they were
     * halfway through. The picker is opened here instead: state rather than a
     * prop, because every card on every page raises it and there is one modal
     * mounted in the layout to answer.
     *
     * `thenCheckout` carries the difference between the cart icon and Buy Now,
     * which is only knowable at the moment of the click.
     */
    variantPicker: { slug: null, name: null, thenCheckout: false },
    openVariantPicker: ({ slug, name, thenCheckout = false }) =>
        set({ variantPicker: { slug, name, thenCheckout } }),
    closeVariantPicker: () =>
        set({ variantPicker: { slug: null, name: null, thenCheckout: false } }),
}));

export default useAppStore;
