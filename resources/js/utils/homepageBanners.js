/**
 * Which switched-on banners go where on the homepage.
 *
 * The hero slider took every active banner, so the promo cards rotated through
 * it as well as sitting in their own grid. Each spot now takes only its own.
 * promo_top was once offered in the admin and shown with the cards, so any
 * saved then stay there. Anything else (the retired popup) shows nowhere.
 *
 * Order is kept as given: the server sends them by sort order.
 */
export function homepageBanners(banners = []) {
    const live = (banners ?? []).filter((b) => b && b.is_active);

    return {
        hero: live.filter((b) => b.position === 'hero'),
        promos: live.filter(
            (b) => b.position === 'promo_side' || b.position === 'promo_top',
        ),
    };
}
