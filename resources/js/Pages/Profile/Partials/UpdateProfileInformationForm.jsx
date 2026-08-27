import { usePage, router, Link } from '@inertiajs/react';
import { useFormik } from 'formik';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import { toast } from '@/Components/Toast';
import { updateProfileSchema } from '@/validations';
import { ROUTES } from '@/constants/endpoints';
import '../Profile.css';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}) {
    const user = usePage().props.auth.user;

    const formik = useFormik({
        initialValues: {
            name: user.name || '',
            email: user.email || '',
        },
        validationSchema: updateProfileSchema,
        onSubmit: (values, { setSubmitting, setErrors }) => {
            router.patch(ROUTES.PROFILE_EDIT, values, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(
                        'Profile information updated successfully!',
                        'Profile Saved',
                    );
                },
                onError: (errs) => {
                    setErrors(errs);
                    toast.error(
                        'Failed to update profile. Please check the inputs.',
                        'Profile Error',
                    );
                },
                onFinish: () => setSubmitting(false),
            });
        },
    });

    return (
        <section className={className}>
            <header>
                <h2 className="profile-section-title">Profile Information</h2>

                <p className="profile-section-desc">
                    Update your account's profile information and email address.
                </p>
            </header>

            <form onSubmit={formik.handleSubmit} className="profile-form-box">
                <FormInput
                    id="name"
                    name="name"
                    label="Full Name"
                    value={formik.values.name}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    error={formik.touched.name && formik.errors.name}
                    autoComplete="name"
                />

                <FormInput
                    id="email"
                    name="email"
                    label="Email Address"
                    type="email"
                    value={formik.values.email}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    error={formik.touched.email && formik.errors.email}
                    autoComplete="username"
                />

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div>
                        <p className="profile-unverified-text">
                            Your email address is unverified.{' '}
                            <Link
                                href={ROUTES.EMAIL_VERIFICATION_NOTIFICATION}
                                method="post"
                                as="button"
                                className="profile-verify-link"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="profile-verification-success">
                                A new verification link has been sent to your
                                email address.
                            </div>
                        )}
                    </div>
                )}

                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={formik.isSubmitting}
                    >
                        Save Changes
                    </Button>
                </div>
            </form>
        </section>
    );
}
