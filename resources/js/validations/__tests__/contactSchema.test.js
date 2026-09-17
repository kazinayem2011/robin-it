import { describe, it, expect } from 'vitest';
import { contactSchema } from '../trackingSchemas';

const message = {
    name: 'Karim Uddin',
    subject: 'Where is my order?',
    message: 'It has been a week since I ordered the build.',
};

const check = async (schema, values) => {
    try {
        await schema.validate(values, { abortEarly: false });
        return [];
    } catch (error) {
        return error.inner.map((e) => e.path);
    }
};

describe('contactSchema', () => {
    /*
     * Demanding an address of everybody left the customers who registered by
     * mobile inventing one, or typing somebody else's.
     */
    it('asks a signed-in customer for neither an address nor a number', async () => {
        expect(await check(contactSchema(true), message)).toEqual([]);
    });

    it('asks a guest for one or the other', async () => {
        expect(await check(contactSchema(false), message)).toContain('email');

        expect(
            await check(contactSchema(false), {
                ...message,
                phone: '01712345678',
            }),
        ).toEqual([]);

        expect(
            await check(contactSchema(false), {
                ...message,
                email: 'karim@example.com',
            }),
        ).toEqual([]);
    });

    it('still refuses an address that is not one, and a number that is not', async () => {
        expect(
            await check(contactSchema(true), {
                ...message,
                email: 'not-an-address',
            }),
        ).toContain('email');

        expect(
            await check(contactSchema(true), { ...message, phone: '12345' }),
        ).toContain('phone');
    });
});
