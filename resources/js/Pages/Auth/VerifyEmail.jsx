import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { MailCheck, ArrowRight } from 'lucide-react';
import { BrandLogo, Button } from '../../Components';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './Auth.css';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post(ROUTES.EMAIL_VERIFICATION_NOTIFICATION);
    };

    return (
        <div className="auth-page-wrapper">
            <Head title={`Verify Email — ${siteConfig.name}`} />

            <div className="auth-card-container">
                <div className="auth-card-header">
                    <BrandLogo variant="auth" />
                    <h1 className="auth-header-title">Verify Your Email</h1>
                    <p className="auth-header-sub">
                        One quick step to secure your account
                    </p>
                </div>

                <div className="auth-card-body">
                    {status === 'verification-link-sent' && (
                        <div className="auth-status-alert">
                            A new verification link has been sent to your email
                            address.
                        </div>
                    )}

                    <p className="auth-verify-copy">
                        <MailCheck size={18} />
                        <span>
                            Thanks for signing up! Please confirm your email by
                            clicking the link we just sent you. Didn&apos;t get
                            it? We&apos;ll happily send another.
                        </span>
                    </p>

                    <form onSubmit={submit}>
                        <Button
                            type="submit"
                            variant="primary"
                            className="btn-block"
                            loading={processing}
                            icon={ArrowRight}
                            iconPosition="right"
                        >
                            Resend Verification Email
                        </Button>
                    </form>

                    <div className="auth-footer-note">
                        Wrong account?{' '}
                        <Link
                            href={ROUTES.LOGOUT}
                            method="post"
                            as="button"
                            className="auth-back-link"
                        >
                            Log out
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
