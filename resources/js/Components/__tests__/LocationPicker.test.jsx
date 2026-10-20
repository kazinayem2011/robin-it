import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const locateStore = vi.fn();
vi.mock('../../services', () => ({
    adminService: { locateStore: (...a) => locateStore(...a) },
}));

const handlers = vi.hoisted(() => ({ click: null }));
vi.mock('../../utils/leaflet', async (real) => {
    const actual = await real();
    const map = {
        setView: vi.fn(),
        on: (event, fn) => (handlers[event] = fn),
        invalidateSize: vi.fn(),
        remove: vi.fn(),
    };
    const marker = {
        addTo: () => marker,
        on: () => marker,
        setLatLng: vi.fn(),
        remove: vi.fn(),
    };
    const L = {
        map: () => map,
        tileLayer: () => ({ addTo: () => {} }),
        divIcon: () => ({}),
        marker: () => marker,
    };
    return { ...actual, loadLeaflet: async () => L };
});

globalThis.ResizeObserver = class {
    observe() {}
    disconnect() {}
};

const { default: LocationPicker } = await import('../LocationPicker');

beforeEach(() => vi.clearAllMocks());

/* The branch form's pin: dropped from a Google Maps link, or clicked into place. */
describe('LocationPicker', () => {
    it('drops the pin where a pasted Google Maps link points', async () => {
        locateStore.mockResolvedValue({
            data: { latitude: 23.7339, longitude: 90.4117 },
        });
        const onChange = vi.fn();
        render(
            <LocationPicker
                latitude={null}
                longitude={null}
                onChange={onChange}
            />,
        );

        fireEvent.change(screen.getByLabelText('Google Maps link'), {
            target: { value: 'https://maps.app.goo.gl/AbC' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Drop pin' }));

        await waitFor(() =>
            expect(onChange).toHaveBeenCalledWith({
                latitude: 23.7339,
                longitude: 90.4117,
                map_link: 'https://maps.app.goo.gl/AbC',
            }),
        );
    });

    it('says why when the link cannot be placed', async () => {
        locateStore.mockRejectedValue(
            new Error('That link does not say where the place is.'),
        );
        render(
            <LocationPicker
                latitude={null}
                longitude={null}
                onChange={vi.fn()}
            />,
        );

        fireEvent.change(screen.getByLabelText('Google Maps link'), {
            target: { value: 'https://www.google.com/maps/search/x' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Drop pin' }));

        expect(
            await screen.findByText(
                'That link does not say where the place is.',
            ),
        ).toBeInTheDocument();
    });

    it('places the pin where the map is clicked', async () => {
        const onChange = vi.fn();
        render(
            <LocationPicker
                latitude={null}
                longitude={null}
                onChange={onChange}
            />,
        );

        await waitFor(() => expect(handlers.click).toBeTypeOf('function'));
        handlers.click({ latlng: { lat: 22.8155, lng: 89.554 } });
        expect(onChange).toHaveBeenCalledWith({
            latitude: 22.8155,
            longitude: 89.554,
        });
    });

    it('says when there is no pin yet, and can remove one', () => {
        const onChange = vi.fn();
        const { rerender } = render(
            <LocationPicker
                latitude={null}
                longitude={null}
                onChange={onChange}
            />,
        );
        expect(screen.getByText(/No pin yet/)).toBeInTheDocument();

        rerender(
            <LocationPicker
                latitude={23.7}
                longitude={90.4}
                onChange={onChange}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove pin' }));
        expect(onChange).toHaveBeenCalledWith({
            latitude: null,
            longitude: null,
            map_link: '',
        });
    });
});
