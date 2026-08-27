import React, { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import FormSelect from '../../Components/FormSelect';
import Checkbox from '../../Components/Checkbox';
import Tabs from '../../Components/Tabs';
import { toast } from '../../Components/Toast';
import { adminService, uploadService } from '../../services';
import { adminSettingsSchema } from '../../validations';
import {
    Save,
    Bell,
    Truck,
    Mail,
    Globe,
    Upload,
    Search,
    Send,
    Percent,
} from 'lucide-react';

const TABS = ['general', 'shipping', 'tax', 'email', 'seo', 'ticker'];

/**
 * The tab lives in the URL so a refresh (or a shared link) reopens the same one
 * instead of snapping back to General.
 *
 * Module scope, not the component body: the popstate effect below calls it, and
 * a function redefined on every render is a dependency that changes on every
 * render.
 */
const tabFromUrl = () => {
    if (typeof window === 'undefined') return 'general';
    const tab = new URLSearchParams(window.location.search).get('tab');
    return TABS.includes(tab) ? tab : 'general';
};

export default function AdminSettings({
    settings = [],
    mailPasswordSet = false,
}) {
    const [activeTab, setActiveTab] = useState(tabFromUrl);

    const selectTab = (key) => {
        setActiveTab(key);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', key);
        // replaceState keeps the back button meaning "previous page", not
        // "previous tab", and avoids an Inertia round-trip.
        window.history.replaceState({}, '', url);
    };

    // Someone using back/forward should land on the tab that URL names.
    useEffect(() => {
        const onPop = () => setActiveTab(tabFromUrl());
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);
    const [uploadingLogo, setUploadingLogo] = useState(false);
    const logoInputRef = useRef(null);
    const [uploadingOg, setUploadingOg] = useState(false);
    const ogInputRef = useRef(null);
    const [testEmail, setTestEmail] = useState('');
    const [sendingTest, setSendingTest] = useState(false);

    const handleSendTest = async () => {
        setSendingTest(true);
        try {
            const res = await adminService.sendTestEmail(testEmail);
            toast.success(
                res?.message || `Test email sent to ${testEmail}.`,
                'SMTP Working',
            );
        } catch (err) {
            // Surface the real SMTP error — "authentication failed",
            // "connection refused" — rather than a generic message.
            toast.error(
                err?.message || 'Could not send the test email.',
                'SMTP Failed',
            );
        } finally {
            setSendingTest(false);
        }
    };

    // Map array of { key, value } to object
    const initialMap = {};
    settings.forEach((s) => {
        initialMap[s.key] = s.value;
    });

    const formik = useFormik({
        initialValues: {
            site_name: initialMap.site_name || 'Robins Computer',

            /*
             * VAT. Off unless the shop turns it on, because switching it on
             * changes what customers are charged.
             *
             * vat_inclusive decides the arithmetic rather than the wording:
             * whether the prices already contain the tax, or it is added at
             * checkout. It defaults to inclusive, which is the usual
             * arrangement in Bangladeshi retail.
             */
            vat_enabled: initialMap.vat_enabled === '1',
            vat_rate: initialMap.vat_rate || '15',
            vat_number: initialMap.vat_number || '',
            vat_inclusive: initialMap.vat_inclusive !== '0',
            site_tagline: initialMap.site_tagline || 'The Store of Technology',
            site_logo: initialMap.site_logo || '/images/logo.png',
            meta_title: initialMap.meta_title || '',
            meta_description: initialMap.meta_description || '',
            meta_keywords: initialMap.meta_keywords || '',
            og_image: initialMap.og_image || '',
            google_analytics_id: initialMap.google_analytics_id || '',
            google_site_verification: initialMap.google_site_verification || '',
            site_address:
                initialMap.site_address ||
                'Shop #301-304, Level 3, IDB Bhaban, Agargaon, Dhaka',
            site_legal_name:
                initialMap.site_legal_name ||
                'Robins Computer & Technology Ltd',
            /*
             * hotline_number, not site_hotline: the header and footer read the
             * former, so edits to the latter changed nothing anyone could see.
             * The old key is carried over as the starting value for an install
             * that only has that one.
             */
            hotline_number:
                initialMap.hotline_number || initialMap.site_hotline || '16789',
            hotline_hours:
                initialMap.hotline_hours || '9:00 AM - 8:00 PM (Everyday)',
            support_email:
                initialMap.support_email || 'support@robinscomputer.com',
            sales_email:
                initialMap.sales_email || 'sales@robinscomputer.com.bd',
            service_center_address:
                initialMap.service_center_address ||
                'Multiplan Center, Dhaka-1205',
            /*
             * ?? not ||, because '' is a real answer here: an admin who clears
             * the note wants it gone, and || would put the default straight
             * back every time the page loaded.
             */
            footer_note:
                initialMap.footer_note ?? 'Built with Precision & Care.',
            announcement_text:
                initialMap.announcement_text ||
                '⚡ Ramadan Tech Fest: Up to 15% Instant Discount on All Genuine Builds! Cash on Delivery Nationwide.',
            announcement_active: initialMap.announcement_active !== '0',
            announcement_badge: initialMap.announcement_badge || 'LIVE OFFER',
            shipping_inside_dhaka: Number(
                initialMap.shipping_inside_dhaka || 60,
            ),
            shipping_outside_dhaka: Number(
                initialMap.shipping_outside_dhaka || 120,
            ),
            free_shipping_threshold: Number(
                initialMap.free_shipping_threshold || 50000,
            ),
            mail_mailer: initialMap.mail_mailer || 'smtp',
            mail_host: initialMap.mail_host || 'smtp.mailtrap.io',
            mail_port: Number(initialMap.mail_port || 2525),
            mail_username: initialMap.mail_username || '',
            mail_password: initialMap.mail_password || '',
            mail_encryption: initialMap.mail_encryption || 'tls',
            mail_from_address:
                initialMap.mail_from_address || 'noreply@robinscomputer.com',
            mail_from_name: initialMap.mail_from_name || 'Robins Computer',
        },
        validationSchema: adminSettingsSchema,
        enableReinitialize: true,
        onSubmit: async (values, { setSubmitting }) => {
            try {
                await adminService.updateSettings(values);
                toast.success(
                    'Site settings and Email SMTP configuration saved successfully!',
                );
                router.reload({ only: ['settings'] });
            } catch (err) {
                toast.error(err?.message || 'Failed to update settings.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const handleOgUpload = async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setUploadingOg(true);
        try {
            const { path } = await uploadService.uploadImage(file, 'brands');
            formik.setFieldValue('og_image', path);
            toast.success(
                'Share image uploaded. Save to apply it.',
                'Upload Complete',
            );
        } catch (err) {
            toast.error(
                err?.message || 'Could not upload that image.',
                'Upload Failed',
            );
        } finally {
            setUploadingOg(false);
            event.target.value = '';
        }
    };

    const handleLogoUpload = async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setUploadingLogo(true);
        try {
            const { path } = await uploadService.uploadImage(file, 'brands');
            formik.setFieldValue('site_logo', path);
            toast.success(
                'Logo uploaded. Save to apply it.',
                'Upload Complete',
            );
        } catch (err) {
            toast.error(
                err?.message || 'Could not upload that logo.',
                'Upload Failed',
            );
        } finally {
            setUploadingLogo(false);
            event.target.value = '';
        }
    };

    return (
        <AdminLayout
            title="Global Site &amp; Email Settings"
            subtitle="Configure Branding, Store Config, Email SMTP, and Header Announcement Tickers"
        >
            <Head title="Admin Settings" />

            <div className="admin-page-container">
                {/* Settings Tabs Navigation */}
                <Tabs
                    tabs={[
                        {
                            key: 'general',
                            label: 'General & Branding',
                            icon: Globe,
                        },
                        {
                            key: 'shipping',
                            label: 'Shipping & Delivery',
                            icon: Truck,
                        },
                        {
                            key: 'tax',
                            label: 'VAT',
                            icon: Percent,
                        },
                        {
                            key: 'email',
                            label: 'Email & SMTP Config',
                            icon: Mail,
                        },
                        {
                            key: 'seo',
                            label: 'SEO & Social',
                            icon: Search,
                        },
                        {
                            key: 'ticker',
                            label: 'Announcement Ticker',
                            icon: Bell,
                        },
                    ]}
                    activeTab={activeTab}
                    onChange={selectTab}
                    variant="enclosed"
                />

                <form
                    onSubmit={formik.handleSubmit}
                    className="admin-settings-form"
                    noValidate
                >
                    {/* TAB 1: General & Branding */}
                    {activeTab === 'general' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-inline">
                                    <Globe
                                        size={18}
                                        className="admin-card-icon"
                                    />
                                    <h3 className="admin-card-title">
                                        Brand Identity &amp; Contacts
                                    </h3>
                                </div>
                            </div>
                            <div className="admin-card-body">
                                <div className="form-row-2col">
                                    <FormInput
                                        label="Platform / Brand Name"
                                        name="site_name"
                                        required
                                        formik={formik}
                                        placeholder="Robins Computer"
                                    />
                                    <FormInput
                                        label="Brand Tagline"
                                        name="site_tagline"
                                        formik={formik}
                                        placeholder="The Store of Technology"
                                    />
                                </div>

                                {/* Used across the site and in every outgoing
                                    email header. Emails need an absolute URL,
                                    which BrandDetails builds from APP_URL. */}
                                <div className="admin-image-field">
                                    <FormInput
                                        label="Brand Logo"
                                        name="site_logo"
                                        formik={formik}
                                        placeholder="/images/logo.png"
                                    />
                                    <div className="admin-image-field-actions">
                                        {formik.values.site_logo && (
                                            <img
                                                src={formik.values.site_logo}
                                                alt="Brand logo preview"
                                                className="admin-logo-preview"
                                            />
                                        )}
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            icon={Upload}
                                            loading={uploadingLogo}
                                            disabled={uploadingLogo}
                                            onClick={() =>
                                                logoInputRef.current?.click()
                                            }
                                        >
                                            {uploadingLogo
                                                ? 'Uploading…'
                                                : 'Upload Logo'}
                                        </Button>
                                        <input
                                            ref={logoInputRef}
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            style={{ display: 'none' }}
                                            onChange={handleLogoUpload}
                                        />
                                    </div>
                                    <small className="admin-field-hint">
                                        Shown on the site and at the top of
                                        every email. A wide PNG with a
                                        transparent background works best —
                                        around 540&times;110.
                                    </small>
                                </div>

                                <div className="admin-field-group">
                                    <FormInput
                                        label="Registered Legal Name"
                                        name="site_legal_name"
                                        formik={formik}
                                        placeholder="Robins Computer & Technology Ltd"
                                    />
                                    <small className="admin-field-hint">
                                        The registered company name, used in the
                                        footer copyright line. Leave off any
                                        trailing full stop — one is added.
                                    </small>
                                </div>

                                <div className="form-row-2col">
                                    <FormInput
                                        label="Official Hotline Phone"
                                        name="hotline_number"
                                        required
                                        formik={formik}
                                        placeholder="16789"
                                    />
                                    <FormInput
                                        label="Hotline Opening Hours"
                                        name="hotline_hours"
                                        formik={formik}
                                        placeholder="9:00 AM - 8:00 PM (Everyday)"
                                    />
                                </div>

                                <div className="form-row-2col">
                                    <FormInput
                                        label="Official Support Email"
                                        name="support_email"
                                        type="email"
                                        required
                                        formik={formik}
                                        placeholder="support@robinscomputer.com"
                                    />
                                    <FormInput
                                        label="Sales Email"
                                        name="sales_email"
                                        type="email"
                                        formik={formik}
                                        placeholder="sales@robinscomputer.com.bd"
                                    />
                                </div>

                                <FormInput
                                    label="Headquarters Physical Address"
                                    name="site_address"
                                    type="textarea"
                                    rows={2}
                                    formik={formik}
                                    placeholder="Shop #301-304, Level 3, IDB Bhaban, Agargaon, Dhaka"
                                />

                                <FormInput
                                    label="Service Centre Address"
                                    name="service_center_address"
                                    formik={formik}
                                    placeholder="Multiplan Center, Dhaka-1205"
                                />

                                <div className="admin-field-group">
                                    <FormInput
                                        label="Footer Closing Note"
                                        name="footer_note"
                                        formik={formik}
                                        placeholder="Built with Precision & Care."
                                    />
                                    <small className="admin-field-hint">
                                        Follows &ldquo;All Rights
                                        Reserved&rdquo; in the footer. Leave it
                                        empty to end the line there.
                                    </small>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* TAB 2: Shipping & Delivery */}
                    {activeTab === 'shipping' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-inline">
                                    <Truck
                                        size={18}
                                        className="admin-card-icon"
                                    />
                                    <h3 className="admin-card-title">
                                        Shipping Rates &amp; Delivery Policies
                                    </h3>
                                </div>
                            </div>
                            <div className="admin-card-body">
                                <div className="form-row-2col">
                                    <FormInput
                                        label="Delivery Inside Dhaka (৳ BDT)"
                                        name="shipping_inside_dhaka"
                                        type="number"
                                        required
                                        formik={formik}
                                    />
                                    <FormInput
                                        label="Delivery Outside Dhaka (৳ BDT)"
                                        name="shipping_outside_dhaka"
                                        type="number"
                                        required
                                        formik={formik}
                                    />
                                </div>

                                <FormInput
                                    label="Free Shipping Spend Threshold (৳ BDT)"
                                    name="free_shipping_threshold"
                                    type="number"
                                    formik={formik}
                                    placeholder="50000"
                                />
                            </div>
                        </div>
                    )}

                    {/* TAB 3: Email / SMTP Configuration */}
                    {activeTab === 'tax' && (
                        <div className="admin-card admin-card-no-margin">
                            <div className="admin-card-header">
                                <div className="admin-card-title-inline">
                                    <Percent
                                        size={18}
                                        className="admin-card-icon"
                                    />
                                    <h3 className="admin-card-title">VAT</h3>
                                </div>
                            </div>
                            <div className="admin-card-body">
                                <Checkbox
                                    name="vat_enabled"
                                    label="Charge VAT on orders"
                                    checked={formik.values.vat_enabled}
                                    onChange={formik.handleChange}
                                />
                                <span className="admin-field-hint">
                                    Off by default. Turning it on changes what
                                    customers are charged, so existing orders
                                    keep the rules they were placed under.
                                </span>

                                {formik.values.vat_enabled && (
                                    <>
                                        <div className="form-row-2col">
                                            <FormInput
                                                label="Rate (%)"
                                                name="vat_rate"
                                                type="number"
                                                required
                                                formik={formik}
                                                placeholder="15"
                                            />
                                            <FormInput
                                                label="VAT registration (BIN)"
                                                name="vat_number"
                                                formik={formik}
                                                placeholder="004123456-0101"
                                            />
                                        </div>

                                        <Checkbox
                                            name="vat_inclusive"
                                            label="Product prices already include VAT"
                                            checked={
                                                formik.values.vat_inclusive
                                            }
                                            onChange={formik.handleChange}
                                        />
                                        {/*
                                         * The setting that changes arithmetic
                                         * rather than wording, so it says what
                                         * each choice does to a real number.
                                         */}
                                        <span className="admin-field-hint">
                                            {formik.values.vat_inclusive
                                                ? `Inclusive — a ৳1,000 product stays ৳1,000 at checkout, of which ৳${(
                                                      1000 -
                                                      1000 /
                                                          (1 +
                                                              (Number(
                                                                  formik.values
                                                                      .vat_rate,
                                                              ) || 0) /
                                                                  100)
                                                  ).toFixed(
                                                      2,
                                                  )} is VAT the shop owes.`
                                                : `Exclusive — a ৳1,000 product becomes ৳${(
                                                      1000 *
                                                      (1 +
                                                          (Number(
                                                              formik.values
                                                                  .vat_rate,
                                                          ) || 0) /
                                                              100)
                                                  ).toFixed(
                                                      2,
                                                  )} at checkout, with the VAT added on top.`}
                                        </span>

                                        <span className="admin-field-hint">
                                            VAT is charged on goods after any
                                            discount, and not on delivery — that
                                            fee is collected for the courier.
                                        </span>
                                    </>
                                )}
                            </div>
                        </div>
                    )}

                    {activeTab === 'email' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-inline">
                                    <Mail
                                        size={18}
                                        className="admin-card-icon"
                                    />
                                    <h3 className="admin-card-title">
                                        Email &amp; SMTP Configuration
                                    </h3>
                                </div>
                            </div>
                            <div className="admin-card-body">
                                <div className="form-row-2col">
                                    <FormSelect
                                        label="Mail Driver"
                                        name="mail_mailer"
                                        formik={formik}
                                        options={[
                                            {
                                                value: 'smtp',
                                                label: 'SMTP (Recommended for Production)',
                                            },
                                            {
                                                value: 'sendmail',
                                                label: 'Sendmail',
                                            },
                                            {
                                                value: 'log',
                                                label: 'Log (Testing / Local)',
                                            },
                                        ]}
                                    />
                                    <FormSelect
                                        label="Mail Encryption"
                                        name="mail_encryption"
                                        formik={formik}
                                        options={[
                                            {
                                                value: 'tls',
                                                label: 'TLS (Port 587)',
                                            },
                                            {
                                                value: 'ssl',
                                                label: 'SSL (Port 465)',
                                            },
                                            { value: 'null', label: 'None' },
                                        ]}
                                    />
                                </div>

                                <div className="form-row-2col">
                                    <FormInput
                                        label="SMTP Host"
                                        name="mail_host"
                                        formik={formik}
                                        placeholder="smtp.mailtrap.io or smtp.gmail.com"
                                    />
                                    <FormInput
                                        label="SMTP Port"
                                        name="mail_port"
                                        type="number"
                                        formik={formik}
                                        placeholder="587"
                                    />
                                </div>

                                <div className="form-row-2col">
                                    <FormInput
                                        label="SMTP Username"
                                        name="mail_username"
                                        formik={formik}
                                        placeholder="apikey or user@example.com"
                                    />
                                    <FormInput
                                        label="SMTP Password"
                                        name="mail_password"
                                        type="password"
                                        formik={formik}
                                        placeholder="••••••••••••"
                                    />
                                </div>

                                <div className="form-row-2col">
                                    <FormInput
                                        label="Sender 'From' Email Address"
                                        name="mail_from_address"
                                        type="email"
                                        formik={formik}
                                        placeholder="noreply@robinscomputer.com"
                                    />
                                    <FormInput
                                        label="Sender 'From' Name"
                                        name="mail_from_name"
                                        formik={formik}
                                        placeholder="Robins Computer Official"
                                    />
                                </div>

                                {mailPasswordSet && (
                                    <p className="admin-field-hint">
                                        A password is already saved. Leave the
                                        field blank to keep it, or type a new
                                        one to replace it.
                                    </p>
                                )}

                                {/* Save first, then send: the test uses the
                                    stored settings, not what is on screen. */}
                                <div className="admin-test-email-row">
                                    <FormInput
                                        label="Send a test email to"
                                        name="test_email_to"
                                        type="email"
                                        value={testEmail}
                                        onChange={(e) =>
                                            setTestEmail(e.target.value)
                                        }
                                        placeholder="you@example.com"
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        icon={Send}
                                        loading={sendingTest}
                                        disabled={sendingTest || !testEmail}
                                        onClick={handleSendTest}
                                    >
                                        {sendingTest
                                            ? 'Sending…'
                                            : 'Send Test Email'}
                                    </Button>
                                </div>
                                <small className="admin-field-hint">
                                    Save your settings first — the test sends
                                    using what is stored, and reports the SMTP
                                    error directly if it fails.
                                </small>
                            </div>
                        </div>
                    )}

                    {/* TAB 4: Announcement Ticker */}
                    {/* TAB: SEO & Social */}
                    {activeTab === 'seo' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-inline">
                                    <Search
                                        size={18}
                                        className="admin-card-icon"
                                    />
                                    <h3 className="admin-card-title">
                                        Search &amp; Social Preview
                                    </h3>
                                </div>
                            </div>
                            <div className="admin-card-body">
                                <FormInput
                                    label="Homepage Meta Title"
                                    name="meta_title"
                                    formik={formik}
                                    placeholder="Robins Computer — Genuine PC Hardware in Bangladesh"
                                />
                                <small className="admin-field-hint">
                                    Shown as the clickable headline in search
                                    results. Around 60 characters reads best.
                                </small>

                                <FormInput
                                    label="Meta Description"
                                    name="meta_description"
                                    type="textarea"
                                    rows={3}
                                    formik={formik}
                                    placeholder="Shop genuine processors, graphics cards and custom gaming PCs with official warranty and cash on delivery across Bangladesh."
                                />
                                <small className="admin-field-hint">
                                    The summary under the title in search
                                    results. Around 155 characters.
                                </small>

                                <FormInput
                                    label="Meta Keywords"
                                    name="meta_keywords"
                                    formik={formik}
                                    placeholder="pc builder bangladesh, graphics card, gaming pc, genuine hardware"
                                />

                                {/* Used when a link is pasted into Facebook,
                                    WhatsApp, X and similar. */}
                                <div className="admin-image-field">
                                    <FormInput
                                        label="Social Share Image"
                                        name="og_image"
                                        formik={formik}
                                        placeholder="/images/og-cover.jpg"
                                    />
                                    <div className="admin-image-field-actions">
                                        {formik.values.og_image && (
                                            <img
                                                src={formik.values.og_image}
                                                alt="Social share preview"
                                                className="admin-logo-preview"
                                            />
                                        )}
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            icon={Upload}
                                            loading={uploadingOg}
                                            disabled={uploadingOg}
                                            onClick={() =>
                                                ogInputRef.current?.click()
                                            }
                                        >
                                            {uploadingOg
                                                ? 'Uploading…'
                                                : 'Upload Image'}
                                        </Button>
                                        <input
                                            ref={ogInputRef}
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            style={{ display: 'none' }}
                                            onChange={handleOgUpload}
                                        />
                                    </div>
                                    <small className="admin-field-hint">
                                        Shown when someone shares a link on
                                        social media. 1200&times;630 works best.
                                    </small>
                                </div>

                                <div className="form-row-2col">
                                    <FormInput
                                        label="Google Analytics ID"
                                        name="google_analytics_id"
                                        formik={formik}
                                        placeholder="G-XXXXXXXXXX"
                                    />
                                    <FormInput
                                        label="Google Site Verification"
                                        name="google_site_verification"
                                        formik={formik}
                                        placeholder="verification token"
                                    />
                                </div>
                            </div>
                        </div>
                    )}

                    {activeTab === 'ticker' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-inline">
                                    <Bell
                                        size={18}
                                        className="admin-card-icon"
                                    />
                                    <h3 className="admin-card-title">
                                        Header Announcement Ticker
                                    </h3>
                                </div>
                            </div>
                            <div className="admin-card-body">
                                <div className="admin-field-group">
                                    <FormInput
                                        label="Broadcast Message Text"
                                        name="announcement_text"
                                        type="textarea"
                                        rows={2}
                                        required
                                        formik={formik}
                                        placeholder="Enter promotional banner announcement..."
                                    />
                                    <small className="admin-field-hint">
                                        Anything before the first colon becomes
                                        a heading that stays put while the rest
                                        scrolls.
                                    </small>
                                </div>

                                {/*
                                 * The header has always rendered this badge and
                                 * there was no field for it, so "LIVE OFFER"
                                 * could not be changed from the admin at all.
                                 */}
                                <div className="admin-field-group">
                                    <FormInput
                                        label="Badge Text"
                                        name="announcement_badge"
                                        formik={formik}
                                        placeholder="LIVE OFFER"
                                    />
                                    <small className="admin-field-hint">
                                        The pill at the far left of the ticker,
                                        beside the pulsing dot.
                                    </small>
                                </div>
                                <Checkbox
                                    name="announcement_active"
                                    label="Display Announcement Bar on Storefront Top Header"
                                    checked={formik.values.announcement_active}
                                    onChange={formik.handleChange}
                                />
                            </div>
                        </div>
                    )}

                    {/* Floating Save Actions */}
                    <div className="admin-form-actions-sticky">
                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            icon={Save}
                            loading={formik.isSubmitting}
                        >
                            Save Configuration
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
