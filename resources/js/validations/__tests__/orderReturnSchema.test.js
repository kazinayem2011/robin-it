import { describe, it, expect } from 'vitest';
import { adminOrderReturnSchema } from '../adminSchemas';

/*
 * The lines are keyed by order item, not a list. As Yup.array() an object
 * never passed, so "Confirm return" refused every time and said nothing.
 */
describe('adminOrderReturnSchema', () => {
    it('accepts a return with a unit coming back', async () => {
        await expect(
            adminOrderReturnSchema.validate({
                note: '',
                lines: { 41: { resellable: '1', damaged: '' } },
            }),
        ).resolves.toBeTruthy();
    });

    it('counts damaged units as coming back too', async () => {
        await expect(
            adminOrderReturnSchema.validate({
                lines: { 41: { resellable: '', damaged: 2 } },
            }),
        ).resolves.toBeTruthy();
    });

    it('refuses a return with nothing entered, and says so', async () => {
        await expect(
            adminOrderReturnSchema.validate({
                lines: { 41: { resellable: '', damaged: '' } },
            }),
        ).rejects.toThrow('Enter how many units came back.');
    });
});
