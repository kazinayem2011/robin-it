import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { UserCog } from 'lucide-react';
import './Profile.css';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <>
            <Head title={`Account Settings — ${siteConfig.name}`} />

            <div className="container profile-page-wrapper">
                <div className="breadcrumbs profile-breadcrumbs">
                    <Link href={ROUTES.HOME}>Home</Link> &gt;
                    <Link href={ROUTES.DASHBOARD}> Account</Link> &gt;
                    <span className="current"> Settings</span>
                </div>

                <div className="profile-page-header">
                    <div className="profile-page-icon">
                        <UserCog size={22} />
                    </div>
                    <div>
                        <h1 className="profile-page-title">Account Settings</h1>
                        <p className="profile-page-sub">
                            Update your details, change your password, or close
                            your account.
                        </p>
                    </div>
                </div>

                <div className="profile-sections">
                    <div className="profile-section-card">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                        />
                    </div>

                    <div className="profile-section-card">
                        <UpdatePasswordForm />
                    </div>

                    <div className="profile-section-card profile-danger-box">
                        <DeleteUserForm />
                    </div>
                </div>
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
Edit.layout = mainLayout;
