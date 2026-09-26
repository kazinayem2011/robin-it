import * as Yup from 'yup';

// The one BD mobile check, shared with every other form.
import { isBDPhone } from '../constants/patterns';

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
                      (value) => !value || isBDPhone(value),
                  )
            : Yup.string()
                  .required('Bangladeshi mobile number is required')
                  .test(
                      'bd-phone',
                      'Please enter a valid 11-digit BD mobile number',
                      (value) => !!value && isBDPhone(value),
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
 *                 A guest leaves a mobile number, the way most customers here
 *                 reach the shop, and an address too if they like.
 */
export const contactSchema = (signedIn = false) =>
    Yup.object().shape({
        name: Yup.string().trim().required('Please tell us your name').max(120),
        email: Yup.string()
            .trim()
            .email('That does not look like an email address')
            .max(180),
        phone: Yup.string()
            .nullable()
            .test(
                'bd-phone',
                'Enter a valid 11-digit mobile number, such as 01711223344',
                (value) => !value || isBDPhone(value),
            )
            .test(
                'guest-phone',
                'Leave us a mobile number, so we can reply.',
                (value) => signedIn || Boolean(value?.trim()),
            ),
        // The service chosen at the top of the form.
        subject: Yup.string()
            .trim()
            .required('Choose what you need help with')
            .max(160, 'Keep the subject under 160 characters'),
        message: Yup.string()
            .trim()
            .required('Tell us a little about the problem')
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
