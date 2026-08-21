import { router } from '@inertiajs/react';
import { useFormik } from 'formik';
import { Button, FormInput, toast } from '@/Components';
import { updatePasswordSchema } from '@/validations';
import { ROUTES } from '@/constants/endpoints';
import '../Profile.css';

export default function UpdatePasswordForm({ className = '' }) {
    const formik = useFormik({
        initialValues: {
            current_password: '',
            password: '',
            password_confirmation: '',
        },
        validationSchema: updatePasswordSchema,
        onSubmit: (values, { setSubmitting, setErrors, resetForm }) => {
            router.put(ROUTES.PASSWORD_UPDATE, values, {
                preserveScroll: true,
                onSuccess: () => {
                    resetForm();
                    toast.success(
                        'Password changed successfully!',
                        'Security Updated',
                    );
                },
                onError: (errs) => {
                    setErrors(errs);
                    toast.error(
                        'Failed to change password. Please check your current password.',
                        'Password Error',
                    );
                },
                onFinish: () => setSubmitting(false),
            });
        },
    });

    return (
        <section className={className}>
            <header>
                <h2 className="profile-section-title">Update Password</h2>

                <p className="profile-section-desc">
                    Ensure your account is using a long, secure password to stay
                    protected.
                </p>
            </header>

            <form onSubmit={formik.handleSubmit} className="profile-form-box">
                <FormInput
                    id="current_password"
                    name="current_password"
                    label="Current Password"
                    type="password"
                    value={formik.values.current_password}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    error={
                        formik.touched.current_password &&
                        formik.errors.current_password
                    }
                    autoComplete="current-password"
                />

                <FormInput
                    id="password"
                    name="password"
                    label="New Password (Min. 8 Characters)"
                    type="password"
                    value={formik.values.password}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    error={formik.touched.password && formik.errors.password}
                    autoComplete="new-password"
                />

                <FormInput
                    id="password_confirmation"
                    name="password_confirmation"
                    label="Confirm New Password"
                    type="password"
                    value={formik.values.password_confirmation}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    error={
                        formik.touched.password_confirmation &&
                        formik.errors.password_confirmation
                    }
                    autoComplete="new-password"
                />

                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={formik.isSubmitting}
                    >
                        Update Password
                    </Button>
                </div>
            </form>
        </section>
    );
}
