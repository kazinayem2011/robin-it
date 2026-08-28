import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import { Smartphone, Lock, ArrowRight, ArrowLeft } from 'lucide-react';
import { BrandLogo } from '../../Components/BrandLogo';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import OtpCodeField from '../../Components/OtpCodeField';
import { forgotPasswordPhoneSchema } from '../../validations';
import { otpService } from '../../services';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './Auth.css';

/**
 * Getting back into an account with a text message.
 *
 * The only route back in was a link sent by email, and a great many customers
 * here signed up with an address they never open — or typed one wrong. For them
 * a forgotten password meant the account was gone, and with it the order
 * history and every warranty record hanging off it. The number they actually
 * use is already on the account.
 *
 * The screen never says whether a number has an account: the shop is not in the
 * business of confirming who its customers are to whoever asks.
 */
export default function ForgotPasswordPhone({
    errors: serverErrors,
    resendSeconds = 60,
}) {
    const [sent, setSent] = useState(false);

    const formik = useFormik({
        initialValues: {
            phone: '',
            code: '',
            password: '',
            password_confirmation: '',
        },
        validationSchema: forgotPasswordPhoneSchema,
        onSubmit: (values, { setSubmitting, setErrors, resetForm }) => {
            router.post(ROUTES.FORGOT_PASSWORD_PHONE, values, {
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

    const requestCode = async () => {
        const problems = await formik.validateForm();

        if (problems.phone) {
            formik.setTouched({ phone: true });
            return;
        }

        formik.setSubmitting(true);

        try {
            await otpService.forPasswordReset(formik.values.phone);
            setSent(true);
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
                    'Could not send a code right now. Try again shortly.',
            );
        } finally {
            formik.setSubmitting(false);
        }
    };

    return (
        <div className="auth-page-wrapper">
            <Head title={`Reset Password by Mobile — ${siteConfig.name}`} />

            <div className="auth-card-container">
                <div className="auth-card-header">
                    <BrandLogo variant="auth" />
                    <h1 className="auth-header-title">Reset by Mobile</h1>
                    <p className="auth-header-sub">
                        We'll text a code to the number on your account, and you
                        can choose a new password.
                    </p>
                </div>

                <div className="auth-card-body">
                    <form onSubmit={formik.handleSubmit} noValidate>
                        <FormInput
                            id="phone"
                            name="phone"
                            required
                            label="Registered Mobile Number"
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
                            disabled={sent}
                            autoFocus
                        />

                        {sent && (
                            <>
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
                                        otpService.forPasswordReset(
                                            formik.values.phone,
                                        )
                                    }
                                    onEditNumber={() => {
                                        setSent(false);
                                        formik.setFieldValue('code', '');
                                    }}
                                />

                                <FormInput
                                    id="password"
                                    name="password"
                                    required
                                    label="New Password (Min. 8 Characters)"
                                    type="password"
                                    value={formik.values.password}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    placeholder="Choose a strong password"
                                    icon={Lock}
                                    error={
                                        (formik.touched.password &&
                                            formik.errors.password) ||
                                        serverErrors?.password
                                    }
                                    autoComplete="new-password"
                                />

                                <FormInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    label="Confirm New Password"
                                    type="password"
                                    value={formik.values.password_confirmation}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    placeholder="Re-enter password"
                                    icon={Lock}
                                    error={
                                        (formik.touched.password_confirmation &&
                                            formik.errors
                                                .password_confirmation) ||
                                        serverErrors?.password_confirmation
                                    }
                                    autoComplete="new-password"
                                />
                            </>
                        )}

                        {sent ? (
                            <Button
                                type="submit"
                                variant="primary"
                                size="lg"
                                fullWidth
                                loading={formik.isSubmitting}
                                icon={ArrowRight}
                                iconPosition="right"
                            >
                                CHANGE MY PASSWORD
                            </Button>
                        ) : (
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
                        )}
                    </form>

                    <div className="auth-footer-note mt-20">
                        Have access to your email?{' '}
                        <Link href={ROUTES.FORGOT_PASSWORD}>
                            Reset by email instead
                        </Link>
                    </div>

                    <div className="auth-footer-note">
                        <Link href={ROUTES.LOGIN} className="auth-back-link">
                            <ArrowLeft size={14} /> Back to Sign In
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
