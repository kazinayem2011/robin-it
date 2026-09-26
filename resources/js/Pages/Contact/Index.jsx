import React, { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { useFormik } from 'formik';
import { mainLayout } from '../../Layouts/MainLayout';
import { Phone, Mail, MapPin, Clock, Send, CheckCircle2 } from 'lucide-react';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import Select from '../../Components/Select';
import { toast } from '../../Components/Toast';
import { contactService } from '../../services';
import { contactSchema } from '../../validations';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import {
    SUPPORT_SERVICES,
    serviceFromQuery,
} from '../../constants/supportServices';
import './Contact.css';

/**
 * @param showrooms Where the shop actually is, so someone who would rather
 *                  walk in than write does not have to go looking.
 * @param contact   The signed-in customer's details; no reason to ask again.
 */
export default function Contact({
    page = null,
    showrooms = [],
    contact = null,
}) {
    const signedIn = Boolean(usePage().props?.auth?.user);

    const [sent, setSent] = useState(null);

    // Read once: another page may open this on a service (?service=…).
    const [startService] = useState(() =>
        typeof window === 'undefined'
            ? ''
            : serviceFromQuery(window.location.search),
    );

    const formik = useFormik({
        initialValues: {
            name: contact?.name || '',
            email: contact?.email || '',
            phone: contact?.phone || '',
            subject: startService,
            message: '',
        },
        validationSchema: contactSchema(signedIn),
        onSubmit: async (
            values,
            { setSubmitting, resetForm, setFieldError, setFieldTouched },
        ) => {
            try {
                const data = await contactService.sendMessage(values);
                // The address, or the number when that was all they left:
                // "we will reply to" with nothing after it read as a fault.
                setSent(values.email || values.phone || null);
                resetForm({
                    values: {
                        name: contact?.name || '',
                        email: contact?.email || '',
                        phone: contact?.phone || '',
                        subject: '',
                        message: '',
                    },
                });
                toast.success(
                    data?.message || 'Message sent.',
                    'Thanks for writing in',
                );
            } catch (error) {
                // Against the box it is about — the shop's answer goes to this
                // address, so one that is somebody else's is refused.
                const address = error?.fieldError?.('email');

                if (address) {
                    setFieldTouched('email', true, false);
                    setFieldError('email', address);
                }

                toast.error(
                    error?.message ||
                        'We could not send that just now. Please try the hotline.',
                    'Message not sent',
                );
            } finally {
                setSubmitting(false);
            }
        },
    });

    return (
        <>
            <Head title={`Contact Us — ${siteConfig.name}`} />

            <div className="contact-page container">
                {/* The heading and the blurb are the shop's, edited under
                    Pages; the form and the showrooms below are not text. */}
                <header className="contact-head">
                    <h1>{page?.title || 'Contact us'}</h1>
                    <p>
                        {page?.subtitle ||
                            'A question about an order, a part, or a warranty — write to us and a person will answer.'}
                    </p>
                    {page?.body && (
                        <div
                            className="contact-intro"
                            dangerouslySetInnerHTML={{ __html: page.body }}
                        />
                    )}
                </header>

                <div className="contact-grid">
                    <div className="contact-form-card">
                        {/*
                         * The form stays after sending rather than being
                         * replaced: people often have a second thing to ask,
                         * and a page that empties itself looks like it lost
                         * what they wrote.
                         */}
                        {sent && (
                            <div className="contact-sent-note">
                                <CheckCircle2 size={18} />
                                <span>
                                    Sent. We will reply to{' '}
                                    <strong>{sent}</strong>, usually within one
                                    working day.{' '}
                                    {/* Signed in, the answer also lands in
                                        their own messages, where they can
                                        write back. A guest's cannot: their
                                        message belongs to no account. */}
                                    {signedIn && (
                                        <>
                                            You can also read it and reply in{' '}
                                            <Link
                                                href={ROUTES.DASHBOARD_MESSAGES}
                                            >
                                                your messages
                                            </Link>
                                            .
                                        </>
                                    )}
                                </span>
                            </div>
                        )}

                        {/*
                         * StarTech's service-desk order: what it is about,
                         * then the problem, then who to answer. Choosing the
                         * service first sends it to the right person before a
                         * word is read. The choice is kept as the subject.
                         */}
                        <form onSubmit={formik.handleSubmit} noValidate>
                            <div className="contact-field">
                                <Select
                                    id="subject"
                                    name="subject"
                                    label="What do you need help with?"
                                    required
                                    placeholder="Choose a service"
                                    options={SUPPORT_SERVICES}
                                    formik={formik}
                                />
                            </div>

                            <div className="contact-field">
                                <label
                                    className="form-control-label"
                                    htmlFor="message"
                                >
                                    Tell us about the problem{' '}
                                    <span className="required-asterisk">*</span>
                                </label>
                                <textarea
                                    id="message"
                                    name="message"
                                    rows="5"
                                    className={`form-control-input ${formik.touched.message && formik.errors.message ? 'has-error' : ''}`}
                                    placeholder="What is happening, the make and model, and anything you have already tried. An order number helps if you bought it here."
                                    value={formik.values.message}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                />
                                <div className="contact-field-foot">
                                    <span className="form-control-error">
                                        {formik.touched.message &&
                                            formik.errors.message}
                                    </span>
                                    <span className="contact-count">
                                        {formik.values.message.length} / 4000
                                    </span>
                                </div>
                            </div>

                            <div className="contact-form-row">
                                <FormInput
                                    id="name"
                                    name="name"
                                    required
                                    label="Name"
                                    placeholder="e.g. Rahim Chowdhury"
                                    value={formik.values.name}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    error={
                                        formik.touched.name &&
                                        formik.errors.name
                                    }
                                />
                                <FormInput
                                    id="phone"
                                    name="phone"
                                    /* What a guest is asked for; signed in,
                                       the reply goes to their messages. */
                                    required={!signedIn}
                                    label={
                                        signedIn ? 'Phone (optional)' : 'Phone'
                                    }
                                    placeholder="01711223344"
                                    isBdPhone={true}
                                    value={formik.values.phone}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    error={
                                        formik.touched.phone &&
                                        formik.errors.phone
                                    }
                                />
                            </div>

                            <FormInput
                                id="email"
                                name="email"
                                type="email"
                                label="Email (optional)"
                                helperText={
                                    signedIn
                                        ? 'We will reply in your messages, and by email if you leave one.'
                                        : 'If you would like the answer in writing as well.'
                                }
                                placeholder="you@example.com"
                                value={formik.values.email}
                                onChange={formik.handleChange}
                                onBlur={formik.handleBlur}
                                error={
                                    formik.touched.email && formik.errors.email
                                }
                            />

                            <Button
                                type="submit"
                                variant="primary"
                                size="lg"
                                icon={Send}
                                loading={formik.isSubmitting}
                            >
                                Request support
                            </Button>
                        </form>
                    </div>

                    <aside>
                        <div className="contact-card">
                            <h2>Faster than email</h2>
                            <a
                                className="contact-line"
                                href={`tel:${siteConfig.hotline}`}
                            >
                                <Phone size={16} />
                                <span>
                                    <strong>{siteConfig.hotline}</strong>
                                    <small>9:00 AM – 9:00 PM, every day</small>
                                </span>
                            </a>
                            <a
                                className="contact-line"
                                href={`mailto:${siteConfig.supportEmail}`}
                            >
                                <Mail size={16} />
                                <span>
                                    <strong>{siteConfig.supportEmail}</strong>
                                    <small>For anything not urgent</small>
                                </span>
                            </a>
                            <div className="contact-line">
                                <Clock size={16} />
                                <span>
                                    <strong>One working day</strong>
                                    <small>Typical reply time</small>
                                </span>
                            </div>
                        </div>

                        {showrooms.length > 0 && (
                            <div className="contact-card">
                                <h2>Come and see us</h2>
                                {showrooms.map((s) => (
                                    <div key={s.id} className="contact-line">
                                        <MapPin size={16} />
                                        <span>
                                            <strong>{s.name}</strong>
                                            <small>
                                                {[s.address, s.city]
                                                    .filter(Boolean)
                                                    .join(', ')}
                                            </small>
                                            {s.phone && (
                                                <small>{s.phone}</small>
                                            )}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </aside>
                </div>
            </div>
        </>
    );
}

Contact.layout = mainLayout;
