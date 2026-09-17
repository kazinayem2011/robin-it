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

/**
 * The Contact page.
 *
 * @param signedIn Neither an address nor a number is demanded of a customer
 *                 who is signed in: the answer appears in their own messages
 *                 and rings their bell. Asking an address of everybody left
 *                 the ones who registered by mobile inventing one, or typing
 *                 somebody else's — which is how an answer reaches a stranger.
 *
 *                 A guest is asked for one or the other, so there is always
 *                 some way to answer. A number given is one the shop could
 *                 actually ring.
 */
export const contactSchema = (signedIn = false) =>
    Yup.object().shape({
        name: Yup.string().trim().required('Please tell us your name').max(120),
        email: Yup.string()
            .trim()
            .email('That does not look like an email address')
            .max(180)
            .when('phone', {
                is: (phone) => !signedIn && !phone,
                then: (schema) =>
                    schema.required(
                        'Leave us an email address or a mobile number, so we can reply.',
                    ),
            }),
        phone: Yup.string()
            .nullable()
            .test(
                'bd-phone',
                'Enter a valid 11-digit BD mobile number, or leave it blank',
                (value) =>
                    !value ||
                    bdPhoneRegex.test(value.trim().replace(/[\s-]/g, '')),
            ),
        subject: Yup.string()
            .trim()
            .required('What is it about?')
            .max(160, 'Keep the subject under 160 characters'),
        message: Yup.string()
            .trim()
            .required('Please write your message')
            .min(10, 'Please say a little more so we can help')
            .max(
                4000,
                'That is longer than we can accept — 4000 characters max',
            ),
    });

export const subscribeSchema = Yup.object().shape({
    email: Yup.string()
        .trim()
        .email('That does not look like an email address')
        .required('Enter your email address'),
});
