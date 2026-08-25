import { describe, it, expect } from 'vitest';
import {
    updateProfileSchema,
    updatePasswordSchema,
    deliveryAddressSchema,
} from '../profileSchemas';

const check = (schema, values) =>
    schema.validate(values, { abortEarly: false }).then(
        () => null,
        (err) => err.errors,
    );

describe('updateProfileSchema', () => {
    const base = {
        name: 'Rahim Chowdhury',
        email: 'rahim@example.com',
        phone: '01712345678',
    };

    it('accepts a complete profile', async () => {
        expect(await check(updateProfileSchema, base)).toBeNull();
    });

    it('refuses a blank name', async () => {
        expect(
            await check(updateProfileSchema, { ...base, name: '' }),
        ).toContain('Name is required');
    });

    it('refuses a malformed email', async () => {
        expect(
            await check(updateProfileSchema, {
                ...base,
                email: 'not-an-email',
            }),
        ).toContain('Please enter a valid email address');
    });

    /* The server requires this, so the browser has to as well — a laxer schema
     * only moves the rejection later. */
    it('requires a mobile number, matching the server', async () => {
        expect(
            await check(updateProfileSchema, { ...base, phone: '' }),
        ).toContain('A mobile number is required');
    });

    it('refuses a number that is not a BD mobile', async () => {
        for (const phone of [
            '12345',
            '0171234567',
            '01212345678',
            'abcdefghijk',
        ]) {
            expect(
                await check(updateProfileSchema, { ...base, phone }),
                phone,
            ).not.toBeNull();
        }
    });

    it('accepts the shapes a BD number is written in', async () => {
        for (const phone of [
            '01712345678',
            '8801712345678',
            '+8801712345678',
        ]) {
            expect(
                await check(updateProfileSchema, { ...base, phone }),
                phone,
            ).toBeNull();
        }
    });
});

describe('updatePasswordSchema', () => {
    it('refuses a confirmation that does not match', async () => {
        expect(
            await check(updatePasswordSchema, {
                current_password: 'old-one',
                password: 'a-new-password',
                password_confirmation: 'something-else',
            }),
        ).toContain('Passwords must match exactly');
    });

    it('refuses a password under eight characters', async () => {
        expect(
            await check(updatePasswordSchema, {
                current_password: 'old-one',
                password: 'short',
                password_confirmation: 'short',
            }),
        ).toContain('New password must be at least 8 characters');
    });
});

describe('deliveryAddressSchema', () => {
    const base = {
        name: 'Rahim Chowdhury',
        phone: '01712345678',
        division: 'Dhaka',
        district: 'Dhaka',
        city: 'Dhanmondi',
        address: 'House 45, Road 12',
    };

    it('accepts an address a courier could deliver to', async () => {
        expect(await check(deliveryAddressSchema, base)).toBeNull();
    });

    it('requires a street line', async () => {
        expect(
            await check(deliveryAddressSchema, { ...base, address: '' }),
        ).toContain('Street address is required');
    });

    it('requires a reachable number', async () => {
        expect(
            await check(deliveryAddressSchema, { ...base, phone: '019' }),
        ).not.toBeNull();
    });

    it('lets the city be left out', async () => {
        expect(
            await check(deliveryAddressSchema, { ...base, city: '' }),
        ).toBeNull();
    });
});
