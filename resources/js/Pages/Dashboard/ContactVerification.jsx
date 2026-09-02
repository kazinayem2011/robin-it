import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { ShieldCheck, ShieldAlert, Mail, Phone } from 'lucide-react';
import Button from '@/Components/Button';
import OtpCodeField from '@/Components/OtpCodeField';
import { toast } from '@/Components/Toast';
import { otpService } from '@/services';
import { formatBdPhone } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';

/**
 * Proving the email address and the mobile number on an account.
 *
 * The account panel could say "not verified" and offer nothing to do about it.
 * For the email there was at least a route — Laravel's — that nothing called.
 * For the mobile there was no flow at all: phone_verified_at was set when
 * registering by mobile and when resetting a password by mobile, so a customer
 * who signed up with an email and added a number later could never confirm it.
 *
 * Only what the account has is shown. An account holds an email or a mobile,
 * not necessarily both, and a row about a number that does not exist is noise.
 */
export default function ContactVerification({ user }) {
    const emailVerified = Boolean(user?.email_verified_at);
    const phoneVerified = Boolean(user?.phone_verified_at);

    const [sendingEmail, setSendingEmail] = useState(false);
    const [codeSent, setCodeSent] = useState(false);
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState('');
    const [busy, setBusy] = useState(false);
    const [resendSeconds, setResendSeconds] = useState(60);

    const nothingToDo =
        (!user?.email || emailVerified) && (!user?.phone || phoneVerified);

    const resendEmail = () => {
        setSendingEmail(true);
        router.post(
            ROUTES.EMAIL_VERIFICATION_NOTIFICATION,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        `We have sent a link to ${user.email}. Open it to confirm the address.`,
                        'Verification sent',
                    ),
                onError: () =>
                    toast.error(
                        'We could not send that just now. Please try again in a minute.',
                        'Not sent',
                    ),
                onFinish: () => setSendingEmail(false),
            },
        );
    };

    const sendCode = async () => {
        setBusy(true);
        setCodeError('');
        try {
            const data = await otpService.toVerifyMyNumber();
            setResendSeconds(data?.data?.resend_in ?? 60);
            setCodeSent(true);
        } catch (error) {
            toast.error(
                error?.message || 'We could not send a code just now.',
                'Not sent',
            );
        } finally {
            setBusy(false);
        }
    };

    const confirmCode = async () => {
        if (!code.trim()) {
            setCodeError('Enter the code we sent you.');
            return;
        }

        setBusy(true);
        setCodeError('');
        try {
            await otpService.confirmMyNumber(code.trim());
            toast.success('Your mobile number is confirmed.', 'Verified');
            // The badge in the sidebar reads from the shared auth props, so the
            // page has to be told rather than just this component.
            router.reload({ only: ['user', 'auth'] });
        } catch (error) {
            setCodeError(
                error?.message || 'That code was not right. Please try again.',
            );
        } finally {
            setBusy(false);
        }
    };

    if (nothingToDo) {
        return (
            <div className="contact-verify-card is-settled">
                <ShieldCheck size={18} />
                <p>
                    Your contact details are confirmed. Order updates will reach
                    you.
                </p>
            </div>
        );
    }

    return (
        <div className="contact-verify-card">
            <h3 className="dash-profile-form-title">Confirm your details</h3>
            <p className="contact-verify-intro">
                Confirmed details are how delivery updates and order
                confirmations reach you.
            </p>

            {user?.email && !emailVerified && (
                <div className="contact-verify-row">
                    <div className="contact-verify-what">
                        <Mail size={15} />
                        <div>
                            <strong>{user.email}</strong>
                            <span className="contact-verify-state">
                                <ShieldAlert size={12} /> Not verified
                            </span>
                        </div>
                    </div>
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={resendEmail}
                        disabled={sendingEmail}
                    >
                        {sendingEmail ? 'Sending…' : 'Send verification link'}
                    </Button>
                </div>
            )}

            {user?.phone && !phoneVerified && (
                <div className="contact-verify-row is-stacked">
                    <div className="contact-verify-what">
                        <Phone size={15} />
                        <div>
                            <strong>{formatBdPhone(user.phone)}</strong>
                            <span className="contact-verify-state">
                                <ShieldAlert size={12} /> Not verified
                            </span>
                        </div>
                    </div>

                    {!codeSent ? (
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={sendCode}
                            disabled={busy}
                        >
                            {busy ? 'Sending…' : 'Send code by SMS'}
                        </Button>
                    ) : (
                        <div className="contact-verify-code">
                            <OtpCodeField
                                phone={user.phone}
                                value={code}
                                onChange={(e) =>
                                    setCode(e?.target?.value ?? e ?? '')
                                }
                                error={codeError}
                                onResend={sendCode}
                                resendSeconds={resendSeconds}
                                disabled={busy}
                            />
                            <Button
                                size="sm"
                                onClick={confirmCode}
                                disabled={busy}
                            >
                                {busy ? 'Checking…' : 'Confirm number'}
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
