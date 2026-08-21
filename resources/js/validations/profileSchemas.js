import * as Yup from 'yup';

export const updateProfileSchema = Yup.object().shape({
    name: Yup.string()
        .required('Name is required')
        .min(2, 'Name must be at least 2 characters')
        .max(100, 'Name cannot exceed 100 characters'),
    email: Yup.string()
        .required('Email address is required')
        .email('Please enter a valid email address'),
});

export const updatePasswordSchema = Yup.object().shape({
    current_password: Yup.string().required('Current password is required'),
    password: Yup.string()
        .required('New password is required')
        .min(8, 'New password must be at least 8 characters'),
    password_confirmation: Yup.string()
        .required('Please confirm your new password')
        .oneOf([Yup.ref('password'), null], 'Passwords must match exactly'),
});
