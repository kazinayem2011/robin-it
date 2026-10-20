/*
 * The maps' shared setup: OpenStreetMap through Leaflet, free and with
 * nothing to sign up for.
 *
 * Leaflet is loaded when a map is first drawn rather than with the page, so
 * pages without a map do not carry it, and nothing touches `window` before
 * there is one.
 */
export const loadLeaflet = async () => {
    const [{ default: L }] = await Promise.all([
        import('leaflet'),
        import('leaflet/dist/leaflet.css'),
    ]);

    return L;
};

export const TILES = {
    url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    // OpenStreetMap's terms ask for this line on every map.
    attribution:
        '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
    maxZoom: 19,
};

/** The whole country, for a map with no pin yet. */
export const BANGLADESH = { center: [23.685, 90.3563], zoom: 7 };

/*
 * The pin: drawn, not an image, so it takes the brand colour from CSS
 * (.branch-pin) and needs none of Leaflet's marker image files.
 */
export const pinIcon = (L) =>
    L.divIcon({
        className: 'branch-pin',
        html: '<svg viewBox="0 0 24 32" width="28" height="38" aria-hidden="true"><path d="M12 0C5.4 0 0 5.3 0 11.9 0 20.8 12 32 12 32s12-11.2 12-20.1C24 5.3 18.6 0 12 0z" fill="currentColor"/><circle cx="12" cy="12" r="4.6" class="branch-pin-hole"/></svg>',
        iconSize: [28, 38],
        iconAnchor: [14, 37],
        popupAnchor: [0, -32],
    });

/** Has a pin: both numbers, and real ones. */
export const hasPin = (place) =>
    Number.isFinite(Number(place?.latitude)) &&
    Number.isFinite(Number(place?.longitude)) &&
    place?.latitude !== null &&
    place?.longitude !== null &&
    place?.latitude !== '' &&
    place?.longitude !== '';

/** The Google Maps directions link the branch cards already used. */
export const directionsUrl = (store) =>
    `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
        `${store.name} ${store.address}`,
    )}`;
