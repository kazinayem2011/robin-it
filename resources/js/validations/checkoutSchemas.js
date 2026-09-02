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
    /*
     * One line, and the zone said outright.
     *
     * This used to be a city, an optional area and a street, with delivery
     * priced by searching the city for the word "dhaka". That worked only while
     * the city had a box of its own — against a single line it would charge
     * "Dhaka Road, Feni" the inside-Dhaka rate. So the customer states the zone
     * and writes the address however they like.
     */
    address: Yup.string()
        .required('Delivery address is required')
        .min(
            10,
            'Please give the full address — house, road, area and district',
        )
        .max(500, 'Address is too long'),
    delivery_zone: Yup.string()
        .required('Choose whether delivery is inside or outside Dhaka')
        .oneOf(
            ['inside_dhaka', 'outside_dhaka'],
            'Choose whether delivery is inside or outside Dhaka',
        ),
    payment: Yup.string().default('cod'),
});
