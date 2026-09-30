import { describe, it, expect } from 'vitest';
import { adminCouponSchema } from '../adminSchemas';

/**
 * A coupon's minimum spend and cap are optional: empty means none. The form
 * started them at ৳1,000 and ৳2,000, so a "10% off" coupon on a ৳90,000 phone
 * quietly gave ৳2,000 — and an empty cap failed the form's own check.
 */
describe('the coupon form', () => {
    const coupon = (extra = {}) => ({
        code: 'PHONE10',
        discount_type: 'percent',
        discount_value: 10,
        usage_limit: 500,
        is_active: true,
        ...extra,
    });

    it('accepts no minimum and no cap', async () => {
        const values = await adminCouponSchema.validate(
            coupon({ min_spend: '', max_discount: '' }),
        );

        expect(values.min_spend).toBeNull();
        expect(values.max_discount).toBeNull();
    });

    it('still takes a cap when one is typed, and refuses a negative one', async () => {
        await expect(
            adminCouponSchema.validate(coupon({ max_discount: 2000 })),
        ).resolves.toMatchObject({ max_discount: 2000 });
        await expect(
            adminCouponSchema.validate(coupon({ max_discount: -1 })),
        ).rejects.toThrow();
    });
});
