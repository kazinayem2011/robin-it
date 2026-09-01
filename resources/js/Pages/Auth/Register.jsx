import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import { User, Mail, Lock, ArrowRight, Smartphone } from 'lucide-react';
import { BrandLogo } from '../../Components/BrandLogo';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import OtpCodeField from '../../Components/OtpCodeField';
import { registerSchema } from '../../validations';
import { otpService } from '../../services';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './Auth.css';

/**
 * Creating an account.
 *
 * In two steps when the shop can send a text: the details, then the code that
 * proves the mobile number belongs to whoever typed it. That number is where
 * every order message goes and what the delivery rider calls, so taking it on
 * trust means a stranger eventually receives somebody's parcel.
 *
 * With no SMS gateway configured the server cannot send a code, says so through
 * `verifyPhone`, and this stays the single-step form it has always been —
 * an unverified number is much better than a shop nobody can register with.
 */
export default function Register({
    errors: serverErrors,
    verifyPhone = false,
    resendSeconds = 60,
}) {
    // The account is not created until the code is checked, so this is a step
    // in one form rather than a separate page with the details in a session.
    const [awaitingCode, setAwaitingCode] = useState(false);

    const formik = useFormik({
        initialValues: {
            name: '',
            email: '',
            phone: '',
            password: '',
            password_confirmation: '',
            code: '',
        },
        validationSchema: registerSchema,
        onSubmit: (values, { setSubmitting, setErrors, resetForm }) => {
            /*
             * Checked here rather than in the schema: whether a code is needed
             * depends on whether one was sent, which yup has no way of knowing.
             * A round trip to be told the field is empty is a wasted second.
             */
            if (verifyPhone && !/^\d{6}$/.test(values.code)) {
                setErrors({ code: 'Enter the six-digit code we sent you.' });
                setSubmitting(false);
                return;
            }

            router.post(ROUTES.REGISTER, values, {
                onFinish: () => setSubmitting(false),
                onError: (errs) => {
                    setErrors(errs);

                    /*
                     * The passwords are cleared only when a password was the
                     * problem.
                     *
                     * Clearing them on every failure means mistyping six digits
                     * costs you the password you typed correctly — and because
                     * the fields empty quietly while the visible complaint is
                     * about the code, the next attempt fails on something the
                     * customer was never told about.
                     */
                    const aboutThePassword =
                        errs?.password || errs?.password_confirmation;

                    resetForm({
                        values: aboutThePassword
                            ? {
                                  ...values,
                                  password: '',
                                  password_confirmation: '',
                              }
                            : values,
                    });
                },
            });
        },
    });

    /**
     * Ask for a code, and only then show the field for it.
     *
     * Everything except the code is checked first, here and again on the
     * server. A code is spent when it is used, so letting somebody reach it
     * with a password that will be refused costs them the code and a minute of
     * waiting for another.
     */
    const requestCode = async () => {
        const problems = await formik.validateForm();
        const stopping = Object.keys(problems).filter((key) => key !== 'code');

        if (stopping.length) {
            formik.setTouched(
                stopping.reduce((all, key) => ({ ...all, [key]: true }), {}),
            );
            return;
        }

        formik.setSubmitting(true);

        try {
            await otpService.forRegistration(formik.values.phone);
            setAwaitingCode(true);
        } catch (err) {
            /*
             * Touched first and without revalidating, then the message.
             *
             * setTouched revalidates by default, and revalidation replaces the
             * errors with whatever the schema says — which about a number the
             * schema is happy with is nothing. Done the other way round the
             * message was set and immediately wiped, so a failed send looked
             * like a button that did nothing at all.
             */
            formik.setTouched({ phone: true }, false);
            formik.setFieldError(
                'phone',
                err?.fieldError?.('phone') ||
                    err?.message ||
                    'Could not send a code to that number.',
            );
        } finally {
            formik.setSubmitting(false);
        }
    };

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
                    <form onSubmit={formik.handleSubmit} noValidate>
                        {/* Full Name */}
                        <FormInput
                            id="name"
                            name="name"
                            required
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

                        {/*
                         * One of these two, not both.
                         *
                         * Signing in has always taken either, and most
                         * customers here have the mobile rather than an
                         * address. Neither carries the asterisk, and each
                         * says what it is for.
                         */}
                        <FormInput
                            id="email"
                            name="email"
                            label="Email Address"
                            helperText="Either this or a mobile number."
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

                        <FormInput
                            id="phone"
                            name="phone"
                            label="Bangladeshi Mobile"
                            helperText="Where order updates and the rider call. Either this or an email address."
                            type="tel"
                            value={formik.values.phone}
                            onChange={formik.handleChange}
                            onBlur={formik.handleBlur}
                            placeholder="017xxxxxxxx"
                            icon={Smartphone}
                            error={
                                (formik.touched.phone && formik.errors.phone) ||
                                serverErrors?.phone
                            }
                            autoComplete="tel"
                            disabled={awaitingCode}
                        />

                        {/* Password */}
                        <FormInput
                            id="password"
                            name="password"
                            required
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
                            required
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

                        {awaitingCode && (
                            <OtpCodeField
                                phone={formik.values.phone}
                                value={formik.values.code}
                                onChange={formik.handleChange}
                                onBlur={formik.handleBlur}
                                error={
                                    (formik.touched.code &&
                                        formik.errors.code) ||
                                    serverErrors?.code
                                }
                                resendSeconds={resendSeconds}
                                onResend={() =>
                                    otpService.forRegistration(
                                        formik.values.phone,
                                    )
                                }
                                onEditNumber={() => {
                                    setAwaitingCode(false);
                                    formik.setFieldValue('code', '');
                                }}
                            />
                        )}

                        {/*
                         * One button doing one of two things, rather than two
                         * buttons: at any moment there is exactly one thing to
                         * press, and it says what it does.
                         */}
                        {verifyPhone && !awaitingCode ? (
                            <Button
                                type="button"
                                variant="primary"
                                size="lg"
                                fullWidth
                                loading={formik.isSubmitting}
                                icon={ArrowRight}
                                iconPosition="right"
                                onClick={requestCode}
                            >
                                SEND ME A CODE
                            </Button>
                        ) : (
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
                        )}
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
