import React, { useEffect, useRef } from 'react';
import {
    loadLeaflet,
    TILES,
    BANGLADESH,
    pinIcon,
    hasPin,
    directionsUrl,
} from '../utils/leaflet';
import './BranchMap.css';

/*
 * Built from text nodes, not an HTML string: a branch's name and address are
 * typed by staff, and a popup is not the place for them to become markup.
 */
const popupFor = (store) => {
    const box = document.createElement('div');
    box.className = 'branch-map-popup';

    const name = document.createElement('strong');
    name.textContent = store.name;
    const address = document.createElement('span');
    address.textContent = store.address;
    const link = document.createElement('a');
    link.href = directionsUrl(store);
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = 'Get Directions';

    box.append(name, address, link);
    return box;
};

/**
 * Every branch on one map, a pin each.
 *
 * `focus` is { id, at }: setting it (at changes on each tap) moves the map to
 * that branch and opens its pin, which is how a branch card's name points at
 * its place on the map.
 */
export default function BranchMap({ stores = [], focus = null }) {
    const boxRef = useRef(null);
    const mapRef = useRef(null);
    const markersRef = useRef(new Map());
    const placed = stores.filter(hasPin);
    const key = placed
        .map((s) => `${s.id}:${s.latitude},${s.longitude}`)
        .join('|');

    useEffect(() => {
        let cancelled = false;
        let map = null;

        loadLeaflet().then((L) => {
            if (cancelled || !boxRef.current) return;

            map = L.map(boxRef.current, { scrollWheelZoom: false });
            mapRef.current = map;
            L.tileLayer(TILES.url, {
                attribution: TILES.attribution,
                maxZoom: TILES.maxZoom,
            }).addTo(map);

            const markers = new Map();
            placed.forEach((store) => {
                const marker = L.marker(
                    [Number(store.latitude), Number(store.longitude)],
                    { icon: pinIcon(L), title: store.name, alt: store.name },
                )
                    .addTo(map)
                    .bindPopup(popupFor(store));
                markers.set(store.id, marker);
            });
            markersRef.current = markers;

            if (placed.length === 1) {
                map.setView(
                    [Number(placed[0].latitude), Number(placed[0].longitude)],
                    15,
                );
            } else if (placed.length > 1) {
                map.fitBounds(
                    placed.map((s) => [
                        Number(s.latitude),
                        Number(s.longitude),
                    ]),
                    { padding: [40, 40] },
                );
            } else {
                map.setView(BANGLADESH.center, BANGLADESH.zoom);
            }
        });

        return () => {
            cancelled = true;
            map?.remove();
            mapRef.current = null;
            markersRef.current = new Map();
        };
        // Redrawn when the set of pins changes, not on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    useEffect(() => {
        if (!focus) return;
        const marker = markersRef.current.get(focus.id);
        const map = mapRef.current;
        if (!marker || !map) return;

        map.flyTo(marker.getLatLng(), 16, { duration: 0.6 });
        marker.openPopup();
    }, [focus]);

    if (placed.length === 0) return null;

    return (
        <div
            ref={boxRef}
            className="branch-map"
            role="region"
            aria-label="Our branches on the map"
        />
    );
}
