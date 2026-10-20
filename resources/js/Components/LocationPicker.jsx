import React, { useEffect, useRef, useState } from 'react';
import Button from './Button';
import { adminService } from '../services';
import {
    loadLeaflet,
    TILES,
    BANGLADESH,
    pinIcon,
    hasPin,
} from '../utils/leaflet';
import './BranchMap.css';

/**
 * A branch's pin, set by hand.
 *
 * Drag the pin onto the building, or click where it should go. Pasting the
 * branch's Google Maps link drops it there first, to nudge if need be. Nothing
 * is saved until the branch form is.
 *
 * @param {{ latitude, longitude, mapLink, onChange: Function }} props
 *        onChange receives { latitude, longitude, map_link? }.
 */
export default function LocationPicker({
    latitude,
    longitude,
    mapLink = '',
    onChange,
}) {
    const boxRef = useRef(null);
    const mapRef = useRef(null);
    const markerRef = useRef(null);
    const leafletRef = useRef(null);
    const changeRef = useRef(onChange);
    const [link, setLink] = useState(mapLink || '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const placed = hasPin({ latitude, longitude });

    changeRef.current = onChange;

    const place = (lat, lng, zoom = null) => {
        const L = leafletRef.current;
        const map = mapRef.current;
        if (!L || !map) return;

        if (markerRef.current) {
            markerRef.current.setLatLng([lat, lng]);
        } else {
            markerRef.current = L.marker([lat, lng], {
                icon: pinIcon(L),
                draggable: true,
                title: 'Drag onto the branch',
            })
                .addTo(map)
                .on('dragend', (e) => {
                    const at = e.target.getLatLng();
                    changeRef.current({
                        latitude: round(at.lat),
                        longitude: round(at.lng),
                    });
                });
        }
        if (zoom) map.setView([lat, lng], zoom);
    };

    useEffect(() => {
        let cancelled = false;
        let observer = null;

        loadLeaflet().then((L) => {
            if (cancelled || !boxRef.current) return;
            leafletRef.current = L;

            const map = L.map(boxRef.current);
            mapRef.current = map;
            L.tileLayer(TILES.url, {
                attribution: TILES.attribution,
                maxZoom: TILES.maxZoom,
            }).addTo(map);

            if (placed) {
                place(Number(latitude), Number(longitude), 16);
            } else {
                map.setView(BANGLADESH.center, BANGLADESH.zoom);
            }

            // A click drops the pin there.
            map.on('click', (e) => {
                place(e.latlng.lat, e.latlng.lng);
                changeRef.current({
                    latitude: round(e.latlng.lat),
                    longitude: round(e.latlng.lng),
                });
            });

            // In a window that opens with an animation the map is measured
            // before it has its size; measure again once it has.
            observer = new ResizeObserver(() => map.invalidateSize());
            observer.observe(boxRef.current);
        });

        return () => {
            cancelled = true;
            observer?.disconnect();
            mapRef.current?.remove();
            mapRef.current = null;
            markerRef.current = null;
        };
        // Drawn once; later pins arrive through place().
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const dropFromLink = async () => {
        if (!link.trim()) return;
        setBusy(true);
        setError('');
        try {
            const res = await adminService.locateStore(link.trim());
            const pin = res?.data ?? res;
            place(pin.latitude, pin.longitude, 17);
            changeRef.current({
                latitude: pin.latitude,
                longitude: pin.longitude,
                map_link: link.trim(),
            });
        } catch (err) {
            setError(err?.message || 'Could not read that link.');
        } finally {
            setBusy(false);
        }
    };

    const clear = () => {
        markerRef.current?.remove();
        markerRef.current = null;
        changeRef.current({ latitude: null, longitude: null, map_link: '' });
        setLink('');
    };

    return (
        <div className="location-picker">
            <span className="auth-label">Location on the map</span>
            <div className="location-picker-link">
                <input
                    type="text"
                    className="auth-text-input"
                    aria-label="Google Maps link"
                    placeholder="Paste the Google Maps link (Share → Copy link)"
                    value={link}
                    onChange={(e) => setLink(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            dropFromLink();
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    loading={busy}
                    onClick={dropFromLink}
                >
                    Drop pin
                </Button>
            </div>
            {error && <span className="location-picker-error">{error}</span>}
            <div
                ref={boxRef}
                className="location-picker-map"
                aria-label="Click or drag the pin onto the branch"
            />
            <div className="location-picker-status">
                {placed ? (
                    <>
                        <span>
                            Pin at {Number(latitude).toFixed(5)},{' '}
                            {Number(longitude).toFixed(5)} — drag it onto the
                            building if it is not there.
                        </span>
                        <button
                            type="button"
                            className="location-picker-clear"
                            onClick={clear}
                        >
                            Remove pin
                        </button>
                    </>
                ) : (
                    <span>
                        No pin yet: click the map where the branch is, or paste
                        its Google Maps link. Without one it is left off the
                        Showrooms map.
                    </span>
                )}
            </div>
        </div>
    );
}

const round = (n) => Math.round(n * 1e7) / 1e7;
