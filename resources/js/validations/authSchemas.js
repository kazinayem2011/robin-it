import * as Yup from 'yup';
import { isBDPhone } from '../constants/patterns';

/**
 * Login Validation Schema (Email or BD Phone)
 */
export const loginSchema = Yup.object().shape({
    login: Yup.string()
        .required('Email address or BD mobile number is required')
        .test(
            'email-or-phone',
            'Please enter a valid email address or 11-digit BD mobile number (e.g. 01711223344)',
            (value) => {
                if (!value) return false;
                const trimmed = value.trim();
                const isEmail = Yup.string().email().isValidSync(trimmed);
                const isPhone = isBDPhone(trimmed);
                return isEmail || isPhone;
            },
        ),
    password: Yup.string()
        .required('Password is required')
        .min(6, 'Password must be at least 6 characters'),
    remember: Yup.boolean(),
});

/**
 * Registration Validation Schema
 */
export const registerSchema = Yup.object().shape(
    {
        name: Yup.string()
            .required('Full name is required')
            .min(2, 'Name must be at least 2 characters')
            .max(100, 'Name cannot exceed 100 characters'),
        /*
         * One of the two, not both.
         *
         * Signing in has always accepted either an address or a mobile, and most
         * customers here have the mobile. Demanding both turned away anyone with
         * only one, so each is required exactly when the other is empty — the same
         * rule the server states with required_without.
         */
        email: Yup.string()
            .email('Please enter a valid email address')
            .when('phone', {
                is: (phone) => !phone,
                then: (schema) =>
                    schema.required(
                        'Give us an email address or a mobile number so we can reach you',
                    ),
            }),
        phone: Yup.string()
            .test(
                'bd-phone',
                'Please enter a valid BD mobile number (e.g. 01711223344)',
                (value) => !value || isBDPhone(value),
            )
            .when('email', {
                is: (email) => !email,
                then: (schema) =>
                    schema.required(
                        'Give us a mobile number or an email address so we can reach you',
                    ),
            }),
        password: Yup.string()
            .required('Password is required')
            .min(8, 'Password must be at least 8 characters'),
        password_confirmation: Yup.string()
            .required('Please confirm your password')
            .oneOf([Yup.ref('password'), null], 'Passwords must match exactly'),
    },
    // Declared because email and phone each depend on the other, and Yup
    // refuses to resolve such a pair unless the cycle is named.
    [['email', 'phone']],
);

/**
 * Forgot Password Validation Schema
 */
export const forgotPasswordSchema = Yup.object().shape({
    email: Yup.string()
        .required('Email address is required')
        .email('Please enter a valid email address'),
});

/**
 * Reset Password Validation Schema
 */
export const resetPasswordSchema = Yup.object().shape({
    token: Yup.string().required(),
    email: Yup.string()
        .required('Email address is required')
        .email('Please enter a valid email address'),
    password: Yup.string()
        .required('New password is required')
        .min(8, 'Password must be at least 8 characters'),
    password_confirmation: Yup.string()
        .required('Please confirm your new password')
        .oneOf([Yup.ref('password'), null], 'Passwords must match exactly'),
});

/**
 * The six digits from a text message.
 *
 * Trimmed and stripped before it is judged: people paste the code with the
 * space their phone put in, and refusing that teaches them nothing.
 */
export const otpCodeSchema = Yup.string()
    .transform((value) => (value || '').replace(/\s+/g, ''))
    .required('Enter the code we sent to your mobile')
    .matches(/^\d{6}$/, 'The code is six digits');

/** Resetting a password with a code rather than an emailed link. */
export const forgotPasswordPhoneSchema = Yup.object().shape({
    phone: Yup.string()
        .required('Mobile number is required')
        .test(
            'bd-phone',
            'Please enter a valid BD mobile number (e.g. 01711223344)',
            (value) => isBDPhone(value),
        ),
    code: otpCodeSchema,
    password: Yup.string()
        .required('Password is required')
        .min(8, 'Password must be at least 8 characters'),
    password_confirmation: Yup.string()
        .required('Please confirm your password')
        .oneOf([Yup.ref('password'), null], 'Passwords must match exactly'),
});
