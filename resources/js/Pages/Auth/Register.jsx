import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import { User, Mail, Lock, ArrowRight } from 'lucide-react';
import { BrandLogo } from '../../Components/BrandLogo';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import { registerSchema } from '../../validations';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './Auth.css';

export default function Register({ errors: serverErrors }) {
    const formik = useFormik({
        initialValues: {
            name: '',
            email: '',
            phone: '',
            password: '',
            password_confirmation: '',
        },
        validationSchema: registerSchema,
        onSubmit: (values, { setSubmitting, setErrors, resetForm }) => {
            router.post(ROUTES.REGISTER, values, {
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
            <Head title={`Create an Account — ${siteConfig.name}`} />

            <div className="auth-card-container">
                {/* Brand Header */}
                <div className="auth-card-header">
                    <BrandLogo variant="auth" />
                    <h1 className="auth-header-title">Create an Account</h1>
                    <p className="auth-header-sub">
                        Register for express checkout, order tracking, and
                        exclusive member discounts.
                    </p>
                </div>

                {/* Card Body */}
                <div className="auth-card-body">
                    <form onSubmit={formik.handleSubmit}>
                        {/* Full Name */}
                        <FormInput
                            id="name"
                            name="name"
                            label="Full Name"
                            value={formik.values.name}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="e.g. Nayem Robin"
                            icon={User}
                            error={
                                (formik.touched.name && formik.errors.name) ||
                                serverErrors?.name
                            }
                            autoComplete="name"
                            autoFocus
                        />

                        {/* Email Address */}
                        <FormInput
                            id="email"
                            name="email"
                            label="Email Address"
                            type="email"
                            value={formik.values.email}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="name@example.com"
                            icon={Mail}
                            error={
                                (formik.touched.email && formik.errors.email) ||
                                serverErrors?.email
                            }
                            autoComplete="username"
                        />

                        {/* Bangladeshi Mobile Number */}
                        <FormInput
                            id="phone"
                            name="phone"
                            label="Bangladeshi Mobile (Optional)"
                            type="tel"
                            value={formik.values.phone}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="017xxxxxxxx"
                            error={
                                (formik.touched.phone && formik.errors.phone) ||
                                serverErrors?.phone
                            }
                            autoComplete="tel"
                        />

                        {/* Password */}
                        <FormInput
                            id="password"
                            name="password"
                            label="Password (Min. 8 Characters)"
                            type="password"
                            value={formik.values.password}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="Create a strong password"
                            icon={Lock}
                            error={
                                (formik.touched.password &&
                                    formik.errors.password) ||
                                serverErrors?.password
                            }
                            autoComplete="new-password"
                        />

                        {/* Confirm Password */}
                        <FormInput
                            id="password_confirmation"
                            name="password_confirmation"
                            label="Confirm Password"
                            type="password"
                            value={formik.values.password_confirmation}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="Re-enter password"
                            icon={Lock}
                            error={
                                (formik.touched.password_confirmation &&
                                    formik.errors.password_confirmation) ||
                                serverErrors?.password_confirmation
                            }
                            autoComplete="new-password"
                        />

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
                            CREATE MY ACCOUNT
                        </Button>
                    </form>

                    {/* Footer Login Link */}
                    <div className="auth-footer-note">
                        Already have an account?{' '}
                        <Link href={ROUTES.LOGIN}>Sign In here</Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
