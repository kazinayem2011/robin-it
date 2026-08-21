import * as Yup from 'yup';

const bdPhoneRegex = /^(?:\+8801|8801|01|1)[3-9]\d{8}$/;

export const trackingSchema = Yup.object().shape({
    order_number: Yup.string()
        .required('Order number is required (e.g. ORD-XXXXXXXXXX)')
        .min(4, 'Order number must be at least 4 characters'),
    phone: Yup.string()
        .required('Bangladeshi mobile number is required')
        .test(
            'bd-phone',
            'Please enter a valid 11-digit BD mobile number',
            (value) => {
                if (!value) return false;
                return bdPhoneRegex.test(value.trim().replace(/[\s-]/g, ''));
            },
        ),
});
