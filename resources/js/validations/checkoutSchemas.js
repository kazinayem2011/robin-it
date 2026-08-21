import * as Yup from 'yup';

const bdPhoneRegex = /^(?:\+8801|8801|01|1)[3-9]\d{8}$/;

/**
 * Checkout Delivery Information Validation Schema
 */
export const checkoutSchema = Yup.object().shape({
    name: Yup.string()
        .required('Full recipient name is required')
        .min(2, 'Name must be at least 2 characters')
        .max(100, 'Name is too long'),
    phone: Yup.string()
        .required('Bangladeshi mobile number is required')
        .test(
            'bd-phone',
            'Please enter a valid 11-digit BD mobile number (e.g. 01711223344)',
            (value) => {
                if (!value) return false;
                return bdPhoneRegex.test(value.trim().replace(/[\s-]/g, ''));
            },
        ),
    city: Yup.string()
        .required('City / District is required')
        .min(2, 'City must be at least 2 characters'),
    zone: Yup.string().nullable(),
    street_address: Yup.string()
        .required('Detailed street address is required')
        .min(
            5,
            'Please provide full house, road, and area details (min. 5 chars)',
        ),
    payment: Yup.string().default('cod'),
});
