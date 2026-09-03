import { describe, it, expect } from 'vitest';
import { addressLabel } from '../Index';

/**
 * One line for a saved address, in the checkout picker.
 *
 * The addresses were cards two lines tall, so four of them pushed the form
 * off a phone screen — the customer scrolled past their own addresses to
 * reach the fields. A select says the same thing in one row, which puts the
 * whole burden on this label being readable at a glance.
 */
describe('addressLabel', () => {
    it('leads with the street, since that is what tells two of yours apart', () => {
        expect(
            addressLabel({
                street_address: 'House 12, Road 5, Dhanmondi',
                delivery_zone: 'inside_dhaka',
            }),
        ).toBe('House 12, Road 5, Dhanmondi — Inside Dhaka');
    });

    it('names the zone, because that is what delivery is priced on', () => {
        expect(
            addressLabel({
                street_address: 'Village Road',
                delivery_zone: 'outside_dhaka',
            }),
        ).toContain('Outside Dhaka');
    });

    /* Saved before the zone was asked for: it still has to say where it is,
       or the customer is picking between two identical-looking lines. */
    it('falls back to the area and city on an older address', () => {
        expect(
            addressLabel({
                street_address: 'House 45',
                zone: 'Gulshan',
                city: 'Dhaka',
            }),
        ).toBe('House 45 — Gulshan, Dhaka');
    });

    it('marks the default, so the pre-selected row is explained', () => {
        expect(
            addressLabel({
                street_address: 'House 45',
                delivery_zone: 'inside_dhaka',
                is_default: true,
            }),
        ).toMatch(/\(default\)$/);
    });

    it('does not leave a dangling separator when there is nothing to add', () => {
        expect(addressLabel({ street_address: 'House 45' })).toBe('House 45');
    });
});
