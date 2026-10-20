import React from 'react';
import { render, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

/*
 * Leaflet stood in for: jsdom draws no maps. What is checked is what the
 * page asks of it — a pin per placed branch, its popup, and fitting them all.
 */
const calls = vi.hoisted(() => ({ markers: [], fit: null, view: null }));
vi.mock('../../utils/leaflet', async (real) => {
    const actual = await real();
    const map = {
        setView: (c, z) => (calls.view = [c, z]),
        fitBounds: (b) => (calls.fit = b),
        flyTo: vi.fn(),
        remove: vi.fn(),
    };
    const L = {
        map: () => map,
        tileLayer: () => ({ addTo: () => {} }),
        divIcon: () => ({}),
        marker: (at, opts) => {
            const m = {
                at,
                opts,
                addTo: () => m,
                bindPopup: (el) => ((m.popup = el), m),
                getLatLng: () => at,
                openPopup: vi.fn(),
            };
            calls.markers.push(m);
            return m;
        },
    };
    return { ...actual, loadLeaflet: async () => L };
});

const { default: BranchMap } = await import('../BranchMap');

const store = (over) => ({
    id: 1,
    name: 'Head Office',
    address: '50 Ruhama Tower',
    latitude: 23.7339,
    longitude: 90.4117,
    ...over,
});

beforeEach(() => {
    calls.markers = [];
    calls.fit = null;
    calls.view = null;
});

describe('BranchMap', () => {
    it('pins each branch that has a place, and fits them all in view', async () => {
        render(
            <BranchMap
                stores={[
                    store(),
                    store({
                        id: 2,
                        name: 'Khulna',
                        latitude: 22.81,
                        longitude: 89.55,
                    }),
                    store({
                        id: 3,
                        name: 'No pin yet',
                        latitude: null,
                        longitude: null,
                    }),
                ]}
            />,
        );

        await waitFor(() => expect(calls.markers).toHaveLength(2));
        expect(calls.fit).toEqual([
            [23.7339, 90.4117],
            [22.81, 89.55],
        ]);
    });

    it("writes the popup as text, with the branch's directions", async () => {
        render(<BranchMap stores={[store({ name: '<b>Head</b> Office' })]} />);

        await waitFor(() => expect(calls.markers).toHaveLength(1));
        const popup = calls.markers[0].popup;
        expect(popup.querySelector('strong').textContent).toBe(
            '<b>Head</b> Office',
        );
        expect(popup.querySelector('b')).toBeNull();
        expect(popup.querySelector('a').textContent).toBe('Get Directions');
        // One branch: close in on it rather than fitting a single point.
        expect(calls.view).toEqual([[23.7339, 90.4117], 15]);
    });

    it('draws nothing when no branch has a pin', () => {
        const { container } = render(
            <BranchMap stores={[store({ latitude: null, longitude: null })]} />,
        );
        expect(container.querySelector('.branch-map')).toBeNull();
    });
});
