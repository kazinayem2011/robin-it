import React from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import { Lock, ShieldCheck } from 'lucide-react';
import { BrandLogo } from '../../Components/BrandLogo';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './Auth.css';

export default function ConfirmPassword() {
    const formik = useFormik({
        initialValues: { password: '' },
        onSubmit: (values, { setSubmitting, setErrors, resetForm }) => {
            router.post(ROUTES.PASSWORD_CONFIRM, values, {
                onFinish: () => {
                    setSubmitting(false);
                    resetForm();
                },
                onError: (errs) => setErrors(errs),
            });
        },
    });

    return (
        <div className="auth-page-wrapper">
            <Head title={`Confirm Password — ${siteConfig.name}`} />

            <div className="auth-card-container">
                <div className="auth-card-header">
                    <BrandLogo variant="auth" />
                    <h1 className="auth-header-title">Confirm Your Password</h1>
                    <p className="auth-header-sub">
                        This is a secure area of your account
                    </p>
                </div>

                <div className="auth-card-body">
                    <p className="auth-verify-copy">
                        <ShieldCheck size={18} />
                        <span>
                            Please confirm your password before continuing.
                        </span>
                    </p>

                    <form onSubmit={formik.handleSubmit}>
                        <FormInput
                            label="Password"
                            name="password"
                            type="password"
                            icon={Lock}
                            required
                            autoFocus
                            formik={formik}
                            placeholder="Enter your password"
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            className="btn-block"
                            loading={formik.isSubmitting}
                        >
                            Confirm
                        </Button>
                    </form>
                </div>
            </div>
        </div>
    );
}
