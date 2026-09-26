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

    /* A number the shop can ring or text back; an address is optional. */
    it('asks a guest for a mobile number, and not for an address', async () => {
        expect(await check(contactSchema(false), message)).toEqual(['phone']);

        expect(
            await check(contactSchema(false), {
                ...message,
                email: 'karim@example.com',
            }),
        ).toEqual(['phone']);

        expect(
            await check(contactSchema(false), {
                ...message,
                phone: '01712345678',
            }),
        ).toEqual([]);
    });

    /* The shared check (isBDPhone): the ways people write a number here. */
    it('takes a mobile however it is written, and refuses what is not one', async () => {
        for (const phone of [
            '01711223344',
            '01711 223344',
            '01711-223344',
            '+8801711223344',
            '+880 1711-223344',
            '1711223344',
        ]) {
            expect(
                await check(contactSchema(false), { ...message, phone }),
            ).toEqual([]);
        }

        for (const phone of ['12345', '01211223344', '0171122334', 'abc']) {
            expect(
                await check(contactSchema(false), { ...message, phone }),
            ).toEqual(['phone']);
        }
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
