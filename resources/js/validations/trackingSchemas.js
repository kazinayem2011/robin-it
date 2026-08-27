import * as Yup from 'yup';

const bdPhoneRegex = /^(?:\+8801|8801|01|1)[3-9]\d{8}$/;

/**
 * @param signedIn A guest proves the order is theirs with the mobile number on
 *                 it. Someone signed in has proved it by signing in, and their
 *                 account need not carry a number at all — registering with an
 *                 email and no phone is allowed. The server still refuses
 *                 anyone else's order.
 */
export const trackingSchema = (signedIn = false) =>
    Yup.object().shape({
        order_number: Yup.string()
            .required('Order number is required (e.g. ORD-XXXXXXXXXX)')
            .min(4, 'Order number must be at least 4 characters'),
        phone: signedIn
            ? Yup.string()
                  .nullable()
                  .test(
                      'bd-phone',
                      'Please enter a valid 11-digit BD mobile number',
                      (value) =>
                          !value ||
                          bdPhoneRegex.test(value.trim().replace(/[\s-]/g, '')),
                  )
            : Yup.string()
                  .required('Bangladeshi mobile number is required')
                  .test(
                      'bd-phone',
                      'Please enter a valid 11-digit BD mobile number',
                      (value) =>
                          !!value &&
                          bdPhoneRegex.test(value.trim().replace(/[\s-]/g, '')),
                  ),
    });
