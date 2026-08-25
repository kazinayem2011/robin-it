import { describe, it, expect, beforeEach } from 'vitest';
import siteConfig, { setSiteSettings, setBrandName } from '../siteConfig';

/*
 * These values were literals, so the shop could rename itself in Site Settings
 * and the footer, header and page titles carried on showing the old details.
 */
describe('siteConfig', () => {
    beforeEach(() => {
        // Put the shipped defaults back between assertions.
        setSiteSettings({
            site_name: 'Robins Computer',
            site_legal_name: 'Robins Computer & Technology Ltd',
            site_tagline: 'The Store of Technology',
            hotline_number: '16789',
            sales_email: 'sales@robinscomputer.com.bd',
            service_center_address: 'Multiplan Center, Dhaka-1205',
            site_address: 'Level 4, IDB Bhaban, Agargaon, Dhaka-1207',
            footer_note: 'Built with Precision & Care.',
        });
    });

    it('takes every field from the settings the server shared', () => {
        setSiteSettings({
            site_name: 'Acme Tech',
            site_legal_name: 'Acme Tech Trading PLC',
            hotline_number: '019000000',
            hotline_hours: '10:00 AM - 6:00 PM',
            sales_email: 'hello@acme.test',
            support_email: 'help@acme.test',
            site_address: '12 Acme Road, Dhaka',
            service_center_address: '3 Repair Lane, Dhaka',
        });

        expect(siteConfig.name).toBe('Acme Tech');
        expect(siteConfig.legalName).toBe('Acme Tech Trading PLC');
        expect(siteConfig.hotline).toBe('019000000');
        expect(siteConfig.hotlineHours).toBe('10:00 AM - 6:00 PM');
        expect(siteConfig.salesEmail).toBe('hello@acme.test');
        expect(siteConfig.supportEmail).toBe('help@acme.test');
        expect(siteConfig.headOffice).toBe('12 Acme Road, Dhaka');
        expect(siteConfig.serviceCenter).toBe('3 Repair Lane, Dhaka');
    });

    /*
     * The footer prints "{legalName}. All Rights Reserved", so a name saved
     * with its own full stop rendered "Ltd.. All Rights Reserved".
     */
    it('strips a trailing full stop from the legal name', () => {
        setSiteSettings({ site_legal_name: 'Acme Tech Trading Ltd.' });

        expect(siteConfig.legalName).toBe('Acme Tech Trading Ltd');
    });

    it('keeps the fallback when a setting is blank or missing', () => {
        setSiteSettings({ site_legal_name: '   ', hotline_number: undefined });

        expect(siteConfig.legalName).toBe('Robins Computer & Technology Ltd');
        expect(siteConfig.hotline).toBe('16789');
    });

    it('ignores a payload that is not an object', () => {
        setSiteSettings(null);
        setSiteSettings('nope');

        expect(siteConfig.name).toBe('Robins Computer');
    });

    it('lets the server-resolved brand name win', () => {
        setSiteSettings({ site_name: 'From Settings' });
        setBrandName('Resolved By Server');

        expect(siteConfig.name).toBe('Resolved By Server');
    });

    /*
     * The footer note is decoration, so an admin who empties the box wants the
     * sentence gone. Everything else is load-bearing and keeps its fallback.
     */
    it('honours a cleared footer note instead of restoring the default', () => {
        setSiteSettings({ footer_note: '' });

        expect(siteConfig.footerNote).toBe('');
    });

    it('takes a custom footer note', () => {
        setSiteSettings({ footer_note: 'Assembled in Dhaka since 2009.' });

        expect(siteConfig.footerNote).toBe('Assembled in Dhaka since 2009.');
    });

    it('keeps the default footer note when the key is absent', () => {
        setSiteSettings({ site_name: 'Acme Tech' });

        expect(siteConfig.footerNote).toBe('Built with Precision & Care.');
    });

    it('builds the logo alt text from the current name and tagline', () => {
        setSiteSettings({ site_name: 'Acme Tech', site_tagline: 'Fast Parts' });

        expect(siteConfig.logo.alt).toBe('Acme Tech — Fast Parts');
    });
});
