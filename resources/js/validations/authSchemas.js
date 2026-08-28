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
export const registerSchema = Yup.object().shape({
    name: Yup.string()
        .required('Full name is required')
        .min(2, 'Name must be at least 2 characters')
        .max(100, 'Name cannot exceed 100 characters'),
    email: Yup.string()
        .required('Email address is required')
        .email('Please enter a valid email address'),
    /*
     * Required, and it always was on the server. This said optional, so
     * anybody who left it blank passed here and was rejected by the back end —
     * and it is the number every order message and the delivery rider use.
     */
    phone: Yup.string()
        .required('Mobile number is required')
        .test(
            'bd-phone',
            'Please enter a valid BD mobile number (e.g. 01711223344)',
            (value) => isBDPhone(value),
        ),
    password: Yup.string()
        .required('Password is required')
        .min(8, 'Password must be at least 8 characters'),
    password_confirmation: Yup.string()
        .required('Please confirm your password')
        .oneOf([Yup.ref('password'), null], 'Passwords must match exactly'),
});

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
