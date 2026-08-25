import React from 'react';
import { useForm } from '@inertiajs/react';
import AccountLayout from './AccountLayout';
import { API_ENDPOINTS } from '@/constants/endpoints';

export default function Profile({ user, navCounts, techPoints }) {
    const profileForm = useForm({
        name: user?.name || '',
        email: user?.email || '',
        phone: user?.phone || '',
    });

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const handleProfileSubmit = (e) => {
        e.preventDefault();
        profileForm.post(API_ENDPOINTS.ACCOUNT.PROFILE, {
            preserveScroll: true,
        });
    };

    const handlePasswordSubmit = (e) => {
        e.preventDefault();
        passwordForm.put(API_ENDPOINTS.ACCOUNT.PASSWORD, {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

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
                    onSubmit={handleProfileSubmit}
                    className="dash-profile-form"
                >
                    <h3 className="dash-profile-form-title">
                        Personal Details
                    </h3>

                    <div className="auth-form-group">
                        <label className="auth-label">Full Name</label>
                        <input
                            type="text"
                            value={profileForm.data.name}
                            onChange={(e) =>
                                profileForm.setData('name', e.target.value)
                            }
                            className={`auth-text-input ${profileForm.errors.name ? 'input-error' : ''}`}
                        />
                        {profileForm.errors.name && (
                            <span className="auth-field-error">
                                {profileForm.errors.name}
                            </span>
                        )}
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">Email Address</label>
                        <input
                            type="email"
                            value={profileForm.data.email}
                            onChange={(e) =>
                                profileForm.setData('email', e.target.value)
                            }
                            className={`auth-text-input ${profileForm.errors.email ? 'input-error' : ''}`}
                        />
                        {profileForm.errors.email && (
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
                                value={profileForm.data.phone}
                                onChange={(e) =>
                                    profileForm.setData('phone', e.target.value)
                                }
                                className={`auth-text-input phone-padded ${profileForm.errors.phone ? 'input-error' : ''}`}
                            />
                        </div>
                        {profileForm.errors.phone && (
                            <span className="auth-field-error">
                                {profileForm.errors.phone}
                            </span>
                        )}
                    </div>

                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={profileForm.processing}
                    >
                        {profileForm.processing
                            ? 'Saving...'
                            : 'Save Profile Changes'}
                    </button>
                </form>

                {/* Password Change Form */}
                <form
                    onSubmit={handlePasswordSubmit}
                    className="dash-password-form"
                >
                    <h3 className="dash-profile-form-title">
                        Change Account Password
                    </h3>

                    <div className="auth-form-group">
                        <label className="auth-label">Current Password</label>
                        <input
                            type="password"
                            value={passwordForm.data.current_password}
                            onChange={(e) =>
                                passwordForm.setData(
                                    'current_password',
                                    e.target.value,
                                )
                            }
                            className={`auth-text-input ${passwordForm.errors.current_password ? 'input-error' : ''}`}
                        />
                        {passwordForm.errors.current_password && (
                            <span className="auth-field-error">
                                {passwordForm.errors.current_password}
                            </span>
                        )}
                    </div>

                    <div className="auth-form-group">
                        <label className="auth-label">New Password</label>
                        <input
                            type="password"
                            value={passwordForm.data.password}
                            onChange={(e) =>
                                passwordForm.setData('password', e.target.value)
                            }
                            className={`auth-text-input ${passwordForm.errors.password ? 'input-error' : ''}`}
                        />
                        {passwordForm.errors.password && (
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
                            value={passwordForm.data.password_confirmation}
                            onChange={(e) =>
                                passwordForm.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                            className={`auth-text-input ${passwordForm.errors.password_confirmation ? 'input-error' : ''}`}
                        />
                        {passwordForm.errors.password_confirmation && (
                            <span className="auth-field-error">
                                {passwordForm.errors.password_confirmation}
                            </span>
                        )}
                    </div>

                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={passwordForm.processing}
                    >
                        {passwordForm.processing
                            ? 'Updating...'
                            : 'Update Password'}
                    </button>
                </form>
            </div>
        </AccountLayout>
    );
}
