import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { useFormik } from 'formik';
import { mainLayout } from '../../Layouts/MainLayout';
import { Phone, Mail, MapPin, Clock, Send, CheckCircle2 } from 'lucide-react';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import { toast } from '../../Components/Toast';
import { contactService } from '../../services';
import { contactSchema } from '../../validations';
import siteConfig from '../../constants/siteConfig';
import './Contact.css';

/**
 * @param showrooms Where the shop actually is, so someone who would rather
 *                  walk in than write does not have to go looking.
 * @param contact   The signed-in customer's details; no reason to ask again.
 */
export default function Contact({ showrooms = [], contact = null }) {
    const [sent, setSent] = useState(null);

    const formik = useFormik({
        initialValues: {
            name: contact?.name || '',
            email: contact?.email || '',
            phone: contact?.phone || '',
            subject: '',
            message: '',
        },
        validationSchema: contactSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                const data = await contactService.sendMessage(values);
                setSent(values.email);
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
                <header className="contact-head">
                    <h1>Contact us</h1>
                    <p>
                        A question about an order, a part, or a warranty — write
                        to us and a person will answer.
                    </p>
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
                                    working day.
                                </span>
                            </div>
                        )}

                        <form onSubmit={formik.handleSubmit} noValidate>
                            <div className="contact-form-row">
                                <FormInput
                                    id="name"
                                    name="name"
                                    label="Your name"
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
                                    id="email"
                                    name="email"
                                    type="email"
                                    label="Email"
                                    placeholder="you@example.com"
                                    value={formik.values.email}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    error={
                                        formik.touched.email &&
                                        formik.errors.email
                                    }
                                />
                            </div>

                            <div className="contact-form-row">
                                <FormInput
                                    id="phone"
                                    name="phone"
                                    label="Mobile number (optional)"
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
                                <FormInput
                                    id="subject"
                                    name="subject"
                                    label="Subject"
                                    placeholder="e.g. Warranty on an RTX 4090"
                                    value={formik.values.subject}
                                    onChange={formik.handleChange}
                                    onBlur={formik.handleBlur}
                                    error={
                                        formik.touched.subject &&
                                        formik.errors.subject
                                    }
                                />
                            </div>

                            <div className="contact-field">
                                <label
                                    className="form-control-label"
                                    htmlFor="message"
                                >
                                    Message{' '}
                                    <span className="required-asterisk">*</span>
                                </label>
                                <textarea
                                    id="message"
                                    name="message"
                                    rows="7"
                                    className={`form-control-input ${formik.touched.message && formik.errors.message ? 'has-error' : ''}`}
                                    placeholder="Tell us what you need. Order numbers and part names help us answer faster."
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

                            <Button
                                type="submit"
                                variant="primary"
                                size="lg"
                                icon={Send}
                                loading={formik.isSubmitting}
                            >
                                Send message
                            </Button>
                        </form>
                    </div>

                    <aside className="contact-aside">
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
