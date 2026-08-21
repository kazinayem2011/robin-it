import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '../../Layouts/AdminLayout';
import { Button, FormInput, FormSelect, Tabs, toast } from '../../Components';
import { adminService } from '../../services';
import { adminSettingsSchema } from '../../validations';
import { Sliders, Save, Bell, Phone, Truck, Mail, Globe } from 'lucide-react';

export default function AdminSettings({ settings = [] }) {
    const [activeTab, setActiveTab] = useState('general');

    // Map array of { key, value } to object
    const initialMap = {};
    settings.forEach((s) => {
        initialMap[s.key] = s.value;
    });

    const formik = useFormik({
        initialValues: {
            site_name: initialMap.site_name || 'Robins Computer',
            site_tagline: initialMap.site_tagline || 'The Store of Technology',
            site_address:
                initialMap.site_address ||
                'Shop #301-304, Level 3, IDB Bhaban, Agargaon, Dhaka',
            site_hotline: initialMap.site_hotline || '09600-ROBIN-IT',
            support_email:
                initialMap.support_email || 'support@robinscomputer.com',
            announcement_text:
                initialMap.announcement_text ||
                '⚡ Ramadan Tech Fest: Up to 15% Instant Discount on All Genuine Builds! Cash on Delivery Nationwide.',
            announcement_active: initialMap.announcement_active !== '0',
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
                toast.error(
                    err.response?.data?.message || 'Failed to update settings.',
                );
            } finally {
                setSubmitting(false);
            }
        },
    });

    return (
        <AdminLayout
            title="Global Site &amp; Email Settings"
            subtitle="Configure Branding, Store Config, Email SMTP, and Header Announcement Tickers"
        >
            <Head title="Admin Settings — Robin IT" />

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
                            key: 'email',
                            label: 'Email & SMTP Config',
                            icon: Mail,
                        },
                        {
                            key: 'ticker',
                            label: 'Announcement Ticker',
                            icon: Bell,
                        },
                    ]}
                    activeTab={activeTab}
                    onChange={setActiveTab}
                    variant="enclosed"
                />

                <form
                    onSubmit={formik.handleSubmit}
                    className="admin-settings-form"
                >
                    {/* TAB 1: General & Branding */}
                    {activeTab === 'general' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-group">
                                    <Globe size={18} className="text-primary" />
                                    <h3>Brand Identity &amp; Contacts</h3>
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

                                <div className="form-row-2col">
                                    <FormInput
                                        label="Official Hotline Phone"
                                        name="site_hotline"
                                        required
                                        formik={formik}
                                        placeholder="09600-ROBIN-IT"
                                    />
                                    <FormInput
                                        label="Official Support Email"
                                        name="support_email"
                                        type="email"
                                        required
                                        formik={formik}
                                        placeholder="support@robinscomputer.com"
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
                            </div>
                        </div>
                    )}

                    {/* TAB 2: Shipping & Delivery */}
                    {activeTab === 'shipping' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-group">
                                    <Truck size={18} className="text-primary" />
                                    <h3>
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
                    {activeTab === 'email' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-group">
                                    <Mail size={18} className="text-primary" />
                                    <h3>Email &amp; SMTP Configuration</h3>
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
                            </div>
                        </div>
                    )}

                    {/* TAB 4: Announcement Ticker */}
                    {activeTab === 'ticker' && (
                        <div className="admin-card">
                            <div className="admin-card-header">
                                <div className="admin-card-title-group">
                                    <Bell size={18} className="text-primary" />
                                    <h3>Header Announcement Ticker</h3>
                                </div>
                            </div>
                            <div className="admin-card-body">
                                <FormInput
                                    label="Broadcast Message Text"
                                    name="announcement_text"
                                    type="textarea"
                                    rows={2}
                                    required
                                    formik={formik}
                                    placeholder="Enter promotional banner announcement..."
                                />
                                <div className="form-checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="announcement_active"
                                        id="announcement_active"
                                        checked={
                                            formik.values.announcement_active
                                        }
                                        onChange={formik.handleChange}
                                    />
                                    <label htmlFor="announcement_active">
                                        Display Announcement Bar on Storefront
                                        Top Header
                                    </label>
                                </div>
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
