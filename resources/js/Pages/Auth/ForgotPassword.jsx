import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import { Mail, ArrowRight, ArrowLeft } from 'lucide-react';
import { BrandLogo, FormInput, Button } from '../../Components';
import { forgotPasswordSchema } from '../../validations';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './Auth.css';

export default function ForgotPassword({ status, errors: serverErrors }) {
    const formik = useFormik({
        initialValues: {
            email: '',
        },
        validationSchema: forgotPasswordSchema,
        onSubmit: (values, { setSubmitting, setErrors }) => {
            router.post(ROUTES.FORGOT_PASSWORD, values, {
                onFinish: () => setSubmitting(false),
                onError: (errs) => setErrors(errs),
            });
        },
    });

    return (
        <div className="auth-page-wrapper">
            <Head title={`Forgot Password — ${siteConfig.name}`} />

            <div className="auth-card-container">
                <div className="auth-card-header">
                    <BrandLogo variant="auth" />
                    <h1 className="auth-header-title">Reset Password</h1>
                    <p className="auth-header-sub">
                        Enter your registered email and we'll send you a
                        password reset link.
                    </p>
                </div>

                <div className="auth-card-body">
                    {status && (
                        <div className="auth-status-alert">{status}</div>
                    )}

                    <form onSubmit={formik.handleSubmit}>
                        <FormInput
                            id="email"
                            name="email"
                            label="Registered Email Address"
                            type="email"
                            value={formik.values.email}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="e.g. name@example.com"
                            icon={Mail}
                            error={
                                (formik.touched.email && formik.errors.email) ||
                                serverErrors?.email
                            }
                            autoComplete="username"
                            autoFocus
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            fullWidth
                            loading={formik.isSubmitting}
                            icon={ArrowRight}
                            iconPosition="right"
                        >
                            SEND RESET LINK
                        </Button>
                    </form>

                    <div className="auth-footer-note mt-20">
                        <Link href={ROUTES.LOGIN} className="auth-back-link">
                            <ArrowLeft size={14} /> Back to Sign In
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
