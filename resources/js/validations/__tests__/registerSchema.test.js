import { describe, it, expect } from 'vitest';
import { registerSchema } from '../authSchemas';

/**
 * An account needs one way to reach its owner, not two.
 *
 * Signing in has always taken either an email address or a Bangladeshi
 * mobile; signing up demanded both, which turned away the customers who only
 * had the mobile — most of them, here.
 */
describe('registerSchema', () => {
    const base = {
        name: 'Robin Rahman',
        password: 'correct-horse',
        password_confirmation: 'correct-horse',
    };

    const check = (values) =>
        registerSchema
            .validate(
                { email: '', phone: '', ...base, ...values },
                { abortEarly: false },
            )
            .then(() => [])
            .catch((e) => e.errors);

    it('accepts an email address on its own', async () => {
        expect(await check({ email: 'robin@example.com' })).toEqual([]);
    });

    it('accepts a mobile number on its own', async () => {
        expect(await check({ phone: '01711223344' })).toEqual([]);
    });

    it('accepts both', async () => {
        expect(
            await check({ email: 'robin@example.com', phone: '01711223344' }),
        ).toEqual([]);
    });

    it('refuses neither, naming both ways out', async () => {
        const errors = await check({});

        expect(errors.join(' ')).toMatch(/email address or a mobile number/i);
        expect(errors.join(' ')).toMatch(/mobile number or an email address/i);
    });

    it('still rejects a mobile number that is not one', async () => {
        expect(await check({ phone: '12345' })).toContain(
            'Please enter a valid BD mobile number (e.g. 01711223344)',
        );
    });

    it('still rejects an address that is not one', async () => {
        expect(await check({ email: 'not-an-address' })).toContain(
            'Please enter a valid email address',
        );
    });
});
