import React, { useEffect, useState } from 'react';
import { useFormik } from 'formik';
import * as Yup from 'yup';
import { Bell, Check } from 'lucide-react';
import Button from './Button';
import stockNotificationService from '../services/stockNotificationService';

const schema = Yup.object().shape({
    email: Yup.string()
        .email('That does not look like an email address')
        .required('Enter an email address so we can tell you'),
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
     * The address on the shopper's account, when they are signed in.
     *
     * Having one settles both the value and the field: there is nothing to ask
     * and nothing to get wrong, so it is filled in and locked. An account with
     * no address on it — which registration does not currently allow, but the
     * form should not assume — falls back to an empty box to type into.
     */
    accountEmail = '',
}) {
    const [done, setDone] = useState(false);
    const [waiting, setWaiting] = useState(0);

    const locked = Boolean(accountEmail);

    const formik = useFormik({
        initialValues: { email: accountEmail },
        validationSchema: schema,
        onSubmit: async (values, { setSubmitting, setFieldError }) => {
            try {
                const res = await stockNotificationService.subscribe({
                    product_id: productId,
                    product_variant_id: variantId,
                    email: values.email,
                });
                setWaiting(res?.waiting ?? waiting + 1);
                setDone(true);
            } catch (err) {
                setFieldError(
                    'email',
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
                        An email goes out the moment it&rsquo;s back in stock.
                    </span>
                </div>
            </div>
        );
    }

    return (
        <form className="pdp-notify" onSubmit={formik.handleSubmit} noValidate>
            <div className="pdp-notify-head">
                <Bell size={17} />
                <div>
                    <strong>Out of stock</strong>
                    <span>
                        {locked
                            ? 'We\u2019ll email you the moment it returns.'
                            : 'Leave your email and we\u2019ll tell you when it returns.'}
                        {waiting > 0 && ` ${waiting} already waiting.`}
                    </span>
                </div>
            </div>

            <div className="pdp-notify-row">
                <input
                    type="email"
                    name="email"
                    value={formik.values.email}
                    onChange={formik.handleChange}
                    onBlur={formik.handleBlur}
                    placeholder="you@example.com"
                    aria-label="Email address"
                    disabled={locked}
                    title={locked ? 'The address on your account' : undefined}
                    className={
                        formik.touched.email && formik.errors.email
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

            {formik.touched.email && formik.errors.email && (
                <span className="pdp-notify-error">{formik.errors.email}</span>
            )}
        </form>
    );
}
