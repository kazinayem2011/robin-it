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
    phone: Yup.string()
        .nullable()
        .transform((value) => value || null)
        .test(
            'bd-phone-optional',
            'Please enter a valid BD mobile number (e.g. 01711223344)',
            (value) => {
                if (!value) return true; // optional — skip validation if empty
                return isBDPhone(value);
            },
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
