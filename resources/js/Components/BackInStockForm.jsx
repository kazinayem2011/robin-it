import React, { useEffect, useState } from 'react';
import { useFormik } from 'formik';
import * as Yup from 'yup';
import { Bell, Check } from 'lucide-react';
import Button from './Button';
import stockNotificationService from '../services/stockNotificationService';
import { isBDPhone } from '../constants/patterns';

const isEmail = (value = '') => Yup.string().email().isValidSync(value.trim());

/*
 * An email address or a mobile number, in the one box, as signing in takes
 * either. Most accounts here are a mobile number, and a guest may have no
 * address to give.
 */
const schema = Yup.object().shape({
    contact: Yup.string()
        .trim()
        .required(
            'Enter an email address or a mobile number so we can tell you',
        )
        .test(
            'email-or-phone',
            'Enter an email address, or an 11-digit mobile number such as 01711223344',
            (value = '') => isEmail(value) || isBDPhone(value),
        ),
});

/**
 * "Tell me when this is back."
 *
 * A shopper who finds something sold out otherwise just leaves. Shown only when
 * the thing they are actually looking at is unavailable — on a variant product
 * that means the chosen option, since the others may be perfectly fine.
 */
export default function BackInStockForm({
    productId,
    variantId = null,
    /*
     * The email, or failing that the mobile, on the shopper's account when
     * they are signed in.
     *
     * Having one settles both the value and the field: there is nothing to ask
     * and nothing to get wrong, so it is filled in and locked.
     */
    accountContact = '',
}) {
    const [done, setDone] = useState(false);
    const [byText, setByText] = useState(false);
    const [waiting, setWaiting] = useState(0);

    const locked = Boolean(accountContact);
    const lockedIsPhone = locked && !isEmail(accountContact);

    const formik = useFormik({
        initialValues: { contact: accountContact },
        validationSchema: schema,
        onSubmit: async (values, { setSubmitting, setFieldError }) => {
            try {
                const res = await stockNotificationService.subscribe({
                    product_id: productId,
                    product_variant_id: variantId,
                    contact: values.contact.trim(),
                });
                setWaiting(res?.waiting ?? waiting + 1);
                setByText(!isEmail(values.contact));
                setDone(true);
            } catch (err) {
                setFieldError(
                    'contact',
                    err?.message || 'We could not save that just now.',
                );
            } finally {
                setSubmitting(false);
            }
        },
    });

    // A new option is a different waiting list.
    useEffect(() => {
        setDone(false);
    }, [productId, variantId]);

    useEffect(() => {
        let cancelled = false;

        stockNotificationService
            .count({ product_id: productId, product_variant_id: variantId })
            .then((res) => {
                if (!cancelled) setWaiting(res?.waiting ?? 0);
            })
            .catch(() => {
                if (!cancelled) setWaiting(0);
            });

        return () => {
            cancelled = true;
        };
    }, [productId, variantId]);

    if (done) {
        return (
            <div className="pdp-notify pdp-notify-done">
                <Check size={18} />
                <div>
                    <strong>We&rsquo;ll let you know.</strong>
                    <span>
                        {byText ? 'A text' : 'An email'} goes out the moment
                        it&rsquo;s back in stock.
                    </span>
                </div>
            </div>
        );
    }

    let prompt =
        'Leave your email or mobile number and we’ll tell you when it returns.';
    if (locked) {
        prompt = lockedIsPhone
            ? 'We’ll text you the moment it returns.'
            : 'We’ll email you the moment it returns.';
    }

    return (
        <form className="pdp-notify" onSubmit={formik.handleSubmit} noValidate>
            <div className="pdp-notify-head">
                <Bell size={17} />
                <div>
                    <strong>Sold Out</strong>
                    <span>
                        {prompt}
                        {waiting > 0 && ` ${waiting} already waiting.`}
                    </span>
                </div>
            </div>

            <div className="pdp-notify-row">
                <input
                    type="text"
                    autoComplete="email"
                    name="contact"
                    value={formik.values.contact}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    placeholder="Email or mobile number"
                    aria-label="Email or mobile number"
                    disabled={locked}
                    title={locked ? 'The contact on your account' : undefined}
                    className={
                        formik.touched.contact && formik.errors.contact
                            ? 'has-error'
                            : ''
                    }
                />
                <Button
                    type="submit"
                    variant="primary"
                    disabled={formik.isSubmitting}
                >
                    {formik.isSubmitting ? 'Saving…' : 'Notify me'}
                </Button>
            </div>

            {formik.touched.contact && formik.errors.contact && (
                <span className="pdp-notify-error">
                    {formik.errors.contact}
                </span>
            )}
        </form>
    );
}
