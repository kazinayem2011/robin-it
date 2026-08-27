import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import { Mail, Lock, ArrowRight } from 'lucide-react';
import { BrandLogo } from '../../Components/BrandLogo';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import { resetPasswordSchema } from '../../validations';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './Auth.css';

export default function ResetPassword({ token, email, errors: serverErrors }) {
    const formik = useFormik({
        initialValues: {
            token: token || '',
            email: email || '',
            password: '',
            password_confirmation: '',
        },
        validationSchema: resetPasswordSchema,
        onSubmit: (values, { setSubmitting, setErrors, resetForm }) => {
            router.post(ROUTES.PASSWORD_RESET, values, {
                onFinish: () => setSubmitting(false),
                onError: (errs) => {
                    setErrors(errs);
                    resetForm({
                        values: {
                            ...values,
                            password: '',
                            password_confirmation: '',
                        },
                    });
                },
            });
        },
    });

    return (
        <div className="auth-page-wrapper">
            <Head title={`Set New Password — ${siteConfig.name}`} />

            <div className="auth-card-container">
                <div className="auth-card-header">
                    <BrandLogo variant="auth" />
                    <h1 className="auth-header-title">Create New Password</h1>
                    <p className="auth-header-sub">
                        Enter your new secure account password below.
                    </p>
                </div>

                <div className="auth-card-body">
                    <form onSubmit={formik.handleSubmit}>
                        {/* Email Address */}
                        <FormInput
                            id="email"
                            name="email"
                            label="Email Address"
                            type="email"
                            value={formik.values.email}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="yourname@example.com"
                            icon={Mail}
                            error={
                                (formik.touched.email && formik.errors.email) ||
                                serverErrors?.email
                            }
                            autoComplete="username"
                        />

                        {/* New Password */}
                        <FormInput
                            id="password"
                            name="password"
                            label="New Password (Min. 8 Characters)"
                            type="password"
                            value={formik.values.password}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="Enter new password"
                            icon={Lock}
                            error={
                                (formik.touched.password &&
                                    formik.errors.password) ||
                                serverErrors?.password
                            }
                            autoComplete="new-password"
                            autoFocus
                        />

                        {/* Confirm New Password */}
                        <FormInput
                            id="password_confirmation"
                            name="password_confirmation"
                            label="Confirm New Password"
                            type="password"
                            value={formik.values.password_confirmation}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="Re-enter new password"
                            icon={Lock}
                            error={
                                (formik.touched.password_confirmation &&
                                    formik.errors.password_confirmation) ||
                                serverErrors?.password_confirmation
                            }
                            autoComplete="new-password"
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
                            UPDATE PASSWORD & SIGN IN
                        </Button>
                    </form>

                    <div className="auth-footer-note mt-20">
                        Remember your password?{' '}
                        <Link href={ROUTES.LOGIN}>Sign In here</Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
