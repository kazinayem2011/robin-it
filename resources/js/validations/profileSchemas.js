import * as Yup from 'yup';
import { isBDPhone } from '../constants/patterns';

export const updateProfileSchema = Yup.object().shape({
    name: Yup.string()
        .required('Name is required')
        .min(2, 'Name must be at least 2 characters')
        .max(100, 'Name cannot exceed 100 characters'),
    email: Yup.string()
        .required('Email address is required')
        .email('Please enter a valid email address'),
    /*
     * Required, and required to be a real Bangladeshi mobile — matching what
     * DashboardController::updateProfile enforces. A schema that is laxer than
     * the server just moves the rejection later.
     *
     * Uniqueness is the server's alone to judge; the browser cannot know which
     * numbers other accounts already hold, and that error comes back through
     * onError.
     */
    phone: Yup.string()
        .required('A mobile number is required')
        .test(
            'bd-phone',
            'Enter a valid 11-digit BD mobile number (e.g. 01711223344)',
            (value) => isBDPhone(value || ''),
        ),
});

/**
 * A delivery address. The courier reads these, so the parts that get a parcel
 * to a door are required and the rest is not.
 */
export const deliveryAddressSchema = Yup.object().shape({
    name: Yup.string()
        .required("Recipient's name is required")
        .max(120, 'Name cannot exceed 120 characters'),
    phone: Yup.string()
        .required('A mobile number is required for delivery')
        .test(
            'bd-phone',
            'Enter a valid 11-digit BD mobile number (e.g. 01711223344)',
            (value) => isBDPhone(value || ''),
        ),
    division: Yup.string().required('Please choose a division'),
    district: Yup.string()
        .required('District is required')
        .max(80, 'District cannot exceed 80 characters'),
    city: Yup.string().max(120, 'City cannot exceed 120 characters'),
    address: Yup.string()
        .required('Street address is required')
        .min(6, 'Please give enough detail to find the door')
        .max(255, 'Address cannot exceed 255 characters'),
    /*
     * Asked rather than derived: the district is typed by hand, and the Dhaka
     * division reaches Gazipur and Tangail, so neither settles what delivery
     * costs. Saved with the address so checkout knows the price before the
     * customer picks it.
     */
    delivery_zone: Yup.string()
        .required('Choose whether this address is inside or outside Dhaka')
        .oneOf(
            ['inside_dhaka', 'outside_dhaka'],
            'Choose whether this address is inside or outside Dhaka',
        ),
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
