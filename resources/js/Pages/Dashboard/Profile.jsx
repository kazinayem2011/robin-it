import React from 'react';
import { useFormik } from 'formik';
import { router } from '@inertiajs/react';
import AccountLayout from './AccountLayout';
import { toast } from '@/Components/Toast';
import { updateProfileSchema, updatePasswordSchema } from '@/validations';
import { API_ENDPOINTS } from '@/constants/endpoints';
import { mainLayout } from '../../Layouts/MainLayout';

/**
 * Profile and password.
 *
 * These were the last two forms in the app posting straight to the server with
 * no client-side checking — everything else already runs Formik against a Yup
 * schema. A blank name or a malformed mobile number made a round trip to be
 * told what the browser already knew.
 *
 * Formik only calls onSubmit once the schema passes, so a request is never sent
 * on invalid input.
 */
export default function Profile({ user, navCounts, techPoints }) {
    const profileForm = useFormik({
        initialValues: {
            name: user?.name || '',
            email: user?.email || '',
            phone: user?.phone || '',
        },
        validationSchema: updateProfileSchema,
        onSubmit: (values, { setSubmitting, setErrors }) => {
            router.post(API_ENDPOINTS.ACCOUNT.PROFILE, values, {
                preserveScroll: true,
                // The server checks things the browser cannot, such as whether
                // the email is already on another account.
                onError: (errors) => setErrors(errors),
                onFinish: () => setSubmitting(false),
            });
        },
    });

    const passwordForm = useFormik({
        initialValues: {
            current_password: '',
            password: '',
            password_confirmation: '',
        },
        validationSchema: updatePasswordSchema,
        onSubmit: (values, { setSubmitting, setErrors, resetForm }) => {
            router.put(API_ENDPOINTS.ACCOUNT.PASSWORD, values, {
                preserveScroll: true,
                onSuccess: () => resetForm(),
                onError: (errors) => {
                    setErrors(errors);
                    toast.error(
                        errors?.current_password ||
                            'Please check the details and try again.',
                    );
                },
                onFinish: () => setSubmitting(false),
            });
        },
    });

    return (
        <AccountLayout
            title="Profile & Security"
            active="profile"
            user={user}
            navCounts={navCounts}
            techPoints={techPoints}
        >
            <div>
                <div className="dash-tab-header">
                    <div>
                        <h2>Profile & Security</h2>
                        <p>
                            Update your personal information, Bangladeshi mobile
                            number, and password.
                        </p>
                    </div>
                </div>

                {/* Personal Info Form */}
                <form
                    onSubmit={profileForm.handleSubmit}
                    className="dash-profile-form"
                >
                    <h3 className="dash-profile-form-title">
                        Personal Details
                    </h3>

                    <div className="auth-form-group">
                        <label className="auth-label">Full Name</label>
                        <input
                            type="text"
                            value={profileForm.values.name}
                            name="name"
                            onBlur={profileForm.handleBlur}
                            onChange={profileForm.handleChange}
                            className={`auth-text-input ${profileForm.touched.name && profileForm.errors.name ? 'input-error' : ''}`}
                            placeholder="e.g. Rahim Chowdhury"
                        />
                        {profileForm.touched.name &&
                            profileForm.errors.name && (
                                <span className="auth-field-error">
                                    {profileForm.errors.name}
                                </span>
                            )}
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">Email Address</label>
                        <input
                            type="email"
                            value={profileForm.values.email}
                            name="email"
                            onBlur={profileForm.handleBlur}
                            onChange={profileForm.handleChange}
                            className={`auth-text-input ${profileForm.touched.email && profileForm.errors.email ? 'input-error' : ''}`}
                            placeholder="you@example.com"
                        />
                        {profileForm.touched.email &&
                            profileForm.errors.email && (
                                <span className="auth-field-error">
                                    {profileForm.errors.email}
                                </span>
                            )}
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">
                            Bangladeshi Mobile Number
                        </label>
                        <div className="auth-input-wrapper">
                            <div className="phone-prefix-box">
                                <span className="bd-flag">🇧🇩</span>
                                <span className="prefix-text">+880</span>
                            </div>
                            <input
                                type="tel"
                                value={profileForm.values.phone}
                                name="phone"
                                onBlur={profileForm.handleBlur}
                                onChange={profileForm.handleChange}
                                className={`auth-text-input phone-padded ${profileForm.touched.phone && profileForm.errors.phone ? 'input-error' : ''}`}
                                placeholder="1712 345678"
                            />
                        </div>
                        {profileForm.touched.phone &&
                            profileForm.errors.phone && (
                                <span className="auth-field-error">
                                    {profileForm.errors.phone}
                                </span>
                            )}
                    </div>

                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={profileForm.isSubmitting}
                    >
                        {profileForm.isSubmitting
                            ? 'Saving...'
                            : 'Save Profile Changes'}
                    </button>
                </form>

                {/* Password Change Form */}
                <form
                    onSubmit={passwordForm.handleSubmit}
                    className="dash-password-form"
                >
                    <h3 className="dash-profile-form-title">
                        Change Account Password
                    </h3>

                    <div className="auth-form-group">
                        <label className="auth-label">Current Password</label>
                        <input
                            type="password"
                            value={passwordForm.values.current_password}
                            name="current_password"
                            onBlur={passwordForm.handleBlur}
                            onChange={passwordForm.handleChange}
                            className={`auth-text-input ${passwordForm.touched.current_password && passwordForm.errors.current_password ? 'input-error' : ''}`}
                            placeholder="Your current password"
                        />
                        {passwordForm.touched.current_password &&
                            passwordForm.errors.current_password && (
                                <span className="auth-field-error">
                                    {passwordForm.errors.current_password}
                                </span>
                            )}
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">New Password</label>
                        <input
                            type="password"
                            value={passwordForm.values.password}
                            name="password"
                            onBlur={passwordForm.handleBlur}
                            onChange={passwordForm.handleChange}
                            className={`auth-text-input ${passwordForm.touched.password && passwordForm.errors.password ? 'input-error' : ''}`}
                            placeholder="At least 8 characters"
                        />
                        {passwordForm.touched.password &&
                            passwordForm.errors.password && (
                                <span className="auth-field-error">
                                    {passwordForm.errors.password}
                                </span>
                            )}
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">
                            Confirm New Password
                        </label>
                        <input
                            type="password"
                            value={passwordForm.values.password_confirmation}
                            name="password_confirmation"
                            onBlur={passwordForm.handleBlur}
                            onChange={passwordForm.handleChange}
                            className={`auth-text-input ${passwordForm.touched.password_confirmation && passwordForm.errors.password_confirmation ? 'input-error' : ''}`}
                            placeholder="Repeat the new password"
                        />
                        {passwordForm.touched.password_confirmation &&
                            passwordForm.errors.password_confirmation && (
                                <span className="auth-field-error">
                                    {passwordForm.errors.password_confirmation}
                                </span>
                            )}
                    </div>

                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={passwordForm.isSubmitting}
                    >
                        {passwordForm.isSubmitting
                            ? 'Updating...'
                            : 'Update Password'}
                    </button>
                </form>
            </div>
        </AccountLayout>
    );
}

// Persistent shell: mounts once, survives navigation.
Profile.layout = mainLayout;
