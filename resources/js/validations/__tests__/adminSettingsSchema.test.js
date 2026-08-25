import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { adminSettingsSchema } from '../adminSchemas';

/*
 * The settings form once required site_hotline in its schema while the field
 * itself had been renamed to hotline_number. Formik refused to submit on a
 * field that was no longer rendered, and with nowhere to show the error the
 * Save button silently did nothing.
 *
 * Resolved from the working directory rather than import.meta.url, which under
 * the jsdom environment is an http:// URL that node:url will not convert.
 */
const SETTINGS_PAGE = resolve(
    process.cwd(),
    'resources/js/Pages/Admin/Settings.jsx',
);

const renderedFieldNames = () => {
    const source = readFileSync(SETTINGS_PAGE, 'utf8');

    return new Set(
        [...source.matchAll(/name="([a-z0-9_]+)"/g)].map((m) => m[1]),
    );
};

const requiredKeys = () =>
    Object.entries(adminSettingsSchema.fields)
        .filter(([, field]) =>
            field.tests?.some((t) => t.OPTIONS?.name === 'required'),
        )
        .map(([key]) => key);

describe('adminSettingsSchema', () => {
    it('only requires fields the form actually renders', () => {
        const rendered = renderedFieldNames();
        const orphaned = requiredKeys().filter((key) => !rendered.has(key));

        expect(orphaned).toEqual([]);
    });

    it('requires the hotline under the key the storefront reads', async () => {
        expect(requiredKeys()).toContain('hotline_number');
        expect(requiredKeys()).not.toContain('site_hotline');
    });

    it('accepts a filled-in branding section', async () => {
        await expect(
            adminSettingsSchema.validate({
                site_name: 'Robins Computer',
                site_legal_name: 'Robins Computer & Technology Ltd',
                hotline_number: '16789',
                hotline_hours: '9:00 AM - 8:00 PM',
                support_email: 'support@robinscomputer.com',
                sales_email: 'sales@robinscomputer.com.bd',
                service_center_address: 'Multiplan Center, Dhaka-1205',
                announcement_text: 'Flash sale',
                shipping_inside_dhaka: 60,
                shipping_outside_dhaka: 120,
            }),
        ).resolves.toBeTruthy();
    });

    it('still rejects a missing hotline', async () => {
        await expect(
            adminSettingsSchema.validate({
                support_email: 'support@robinscomputer.com',
                announcement_text: 'Flash sale',
                shipping_inside_dhaka: 60,
                shipping_outside_dhaka: 120,
            }),
        ).rejects.toThrow(/hotline/i);
    });

    /* validateAt, so the other required fields do not report first. */
    it('rejects a malformed sales address', async () => {
        await expect(
            adminSettingsSchema.validateAt('sales_email', {
                sales_email: 'not-an-email',
            }),
        ).rejects.toThrow(/invalid email/i);
    });

    it('accepts a blank sales address, which is optional', async () => {
        await expect(
            adminSettingsSchema.validateAt('sales_email', { sales_email: '' }),
        ).resolves.toBeDefined();
    });
});
