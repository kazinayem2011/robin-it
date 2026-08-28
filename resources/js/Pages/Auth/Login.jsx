import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import { Mail, Lock, Phone, Sparkles, ArrowRight } from 'lucide-react';
import { BrandLogo } from '../../Components/BrandLogo';
import Button from '../../Components/Button';
import Checkbox from '../../Components/Checkbox';
import FormInput from '../../Components/FormInput';
import { loginSchema } from '../../validations';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { isBDPhone } from '../../constants/patterns';
import './Auth.css';

export default function Login({
    status,
    canResetPassword,
    errors: serverErrors,
}) {
    const formik = useFormik({
        initialValues: {
            login: '',
            password: '',
            remember: false,
        },
        validationSchema: loginSchema,
        onSubmit: (values, { setSubmitting, setErrors }) => {
            router.post(ROUTES.LOGIN, values, {
                onFinish: () => setSubmitting(false),
                onError: (errs) => setErrors(errs),
            });
        },
    });

    const handleQuickLogin = (loginVal, passVal) => {
        // Directly submit via Inertia — bypasses Formik async setValues timing issue
        formik.setSubmitting(true);
        router.post(
            ROUTES.LOGIN,
            { login: loginVal, password: passVal, remember: false },
            {
                onFinish: () => formik.setSubmitting(false),
                onError: (errs) => {
                    formik.setSubmitting(false);
                    formik.setErrors(errs);
                },
            },
        );
    };

    const isPhoneNumber =
        formik.values.login.trim().length > 0 && isBDPhone(formik.values.login);

    return (
        <div className="auth-page-wrapper">
            <Head title={`Sign In — ${siteConfig.name}`} />

            <div className="auth-card-container">
                {/* Brand Header */}
                <div className="auth-card-header">
                    <BrandLogo variant="auth" />
                    <h1 className="auth-header-title">Welcome Back</h1>
                    <p className="auth-header-sub">
                        Access your order tracking, custom PC builds, and
                        wishlists.
                    </p>
                </div>

                {/* Card Body */}
                <div className="auth-card-body">
                    {/* Quick Demo Credentials — One-click login */}
                    <div className="auth-demo-helper">
                        <div className="demo-helper-title">
                            <Sparkles size={14} />
                            <span>Quick Demo Accounts:</span>
                        </div>
                        <div className="demo-helper-pills">
                            <button
                                type="button"
                                className="demo-pill admin"
                                disabled={formik.isSubmitting}
                                onClick={() =>
                                    handleQuickLogin(
                                        'admin@robinit.com',
                                        'password',
                                    )
                                }
                            >
                                {formik.isSubmitting ? 'Signing in…' : 'Owner'}
                            </button>
                            <button
                                type="button"
                                className="demo-pill customer"
                                disabled={formik.isSubmitting}
                                onClick={() =>
                                    handleQuickLogin(
                                        'customer@robinit.com',
                                        'password',
                                    )
                                }
                            >
                                {formik.isSubmitting
                                    ? 'Signing in…'
                                    : 'Customer'}
                            </button>
                        </div>
                    </div>

                    {status && (
                        <div className="auth-status-alert">{status}</div>
                    )}

                    <form onSubmit={formik.handleSubmit} noValidate>
                        {/* Email or Bangladeshi Mobile Input */}
                        <FormInput
                            id="login"
                            name="login"
                            required
                            label="Email Address or Mobile Number"
                            type="text"
                            value={formik.values.login}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="e.g. 01712345678 or name@example.com"
                            icon={isPhoneNumber ? Phone : Mail}
                            error={
                                (formik.touched.login && formik.errors.login) ||
                                serverErrors?.email ||
                                serverErrors?.phone
                            }
                            autoComplete="username"
                            autoFocus
                        />

                        {/* Password */}
                        <FormInput
                            id="password"
                            name="password"
                            required
                            label="Password"
                            type="password"
                            value={formik.values.password}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="Enter your account password"
                            icon={Lock}
                            error={
                                (formik.touched.password &&
                                    formik.errors.password) ||
                                serverErrors?.password
                            }
                            autoComplete="current-password"
                        />

                        {/* Remember Me & Forgot Password */}
                        <div className="auth-options-row">
                            <Checkbox
                                name="remember"
                                label="Remember me"
                                checked={formik.values.remember}
                                onChange={formik.handleChange}
                            />

                            {canResetPassword && (
                                <Link
                                    href={ROUTES.FORGOT_PASSWORD}
                                    className="forgot-link"
                                >
                                    Forgot Password?
                                </Link>
                            )}
                        </div>

                        {/* Reusable Primary Button */}
                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            fullWidth
                            loading={formik.isSubmitting}
                            icon={ArrowRight}
                            iconPosition="right"
                        >
                            {`SIGN IN TO ${siteConfig.name.toUpperCase()}`}
                        </Button>
                    </form>

                    {/* Footer Register Link */}
                    <div className="auth-footer-note">
                        Don't have an account yet?{' '}
                        <Link href={ROUTES.REGISTER}>Create an Account</Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
