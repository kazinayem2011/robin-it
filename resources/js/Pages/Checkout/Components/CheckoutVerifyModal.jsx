import { useEffect, useState } from 'react';
import { ArrowLeft, Lock, Mail, Smartphone } from 'lucide-react';
import Modal from '../../../Components/Modal';
import Button from '../../../Components/Button';
import FormInput from '../../../Components/FormInput';
import OtpCodeField from '../../../Components/OtpCodeField';
import { ROUTES } from '../../../constants/endpoints';

const TITLES = {
    choose: 'Which account is this order for?',
    code: 'Confirm your mobile number',
    password: 'Sign in to continue',
};

const FORM_ID = 'checkout-verify-form';

/**
 * Proving who is placing the order, in a window over the delivery form.
 *
 * The code used to appear inside the form, between the phone number and the
 * address, which pushed the rest of the form down and left the customer
 * unsure whether they were still filling in an address or signing in. A
 * window says plainly that this is a separate step, and the details behind it
 * stay exactly as typed.
 *
 * Three steps, one at a time:
 *
 *   choose    the email and the mobile belong to different accounts — or the
 *             email has one and the mobile does not — so the customer says
 *             which the order is for
 *   code      the six digits texted to the mobile
 *   password  the chosen account's password, for an email account, or for a
 *             mobile account whose owner would rather not wait for a text
 *
 * The page owns the flow; this only draws the step it is given and reports
 * what was entered.
 *
 * @param step         'choose' | 'code' | 'password', or null when closed
 * @param phoneAccount on the choose step: whether the mobile has an account
 * @param login        on the password step: the email or mobile signing in
 * @param error        the server's objection to what was entered, if any
 */
export default function CheckoutVerifyModal({
    step = null,
    phone = '',
    email = '',
    phoneAccount = false,
    login = '',
    canGoBack = false,
    busy = false,
    error = null,
    resendSeconds = 60,
    onChoose,
    onSubmitCode,
    onSubmitPassword,
    onResend,
    onUsePassword,
    onBack,
    onEditNumber,
    onClose,
}) {
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');

    // Each step starts empty: a code typed for one text is no use for the next.
    useEffect(() => {
        setCode('');
        setPassword('');
    }, [step, login]);

    if (!step) return null;

    const signingInWithEmail = login.includes('@');

    const submit = (event) => {
        event.preventDefault();

        if (busy) return;

        if (step === 'code') onSubmitCode?.(code);
        if (step === 'password') onSubmitPassword?.(password);
    };

    const footer =
        step === 'choose' ? null : (
            <div className="checkout-verify-footer">
                {canGoBack && (
                    <Button
                        variant="ghost"
                        icon={ArrowLeft}
                        onClick={onBack}
                        disabled={busy}
                    >
                        Back
                    </Button>
                )}
                <Button
                    type="submit"
                    form={FORM_ID}
                    variant="primary"
                    loading={busy}
                >
                    {step === 'code' ? 'Confirm Order' : 'Sign in & Continue'}
                </Button>
            </div>
        );

    return (
        <Modal
            isOpen
            title={TITLES[step]}
            onClose={busy ? undefined : onClose}
            maxWidth="460px"
            footer={footer}
        >
            {step === 'choose' && (
                <div className="checkout-verify">
                    <p className="checkout-verify-intro">
                        {phoneAccount ? (
                            <>
                                <strong>{email}</strong> and{' '}
                                <strong>{phone}</strong> belong to different
                                accounts.
                            </>
                        ) : (
                            <>
                                <strong>{email}</strong> already has an account.
                            </>
                        )}{' '}
                        Pick the one this order should go to.
                    </p>

                    <div className="checkout-verify-choices">
                        <button
                            type="button"
                            className="checkout-verify-choice"
                            onClick={() => onChoose?.('email')}
                            disabled={busy}
                        >
                            <Mail size={18} />
                            <span className="checkout-verify-choice-text">
                                <strong>{email}</strong>
                                <span>
                                    Sign in with this account's password
                                </span>
                            </span>
                        </button>

                        <button
                            type="button"
                            className="checkout-verify-choice"
                            onClick={() => onChoose?.('phone')}
                            disabled={busy}
                        >
                            <Smartphone size={18} />
                            <span className="checkout-verify-choice-text">
                                <strong>{phone}</strong>
                                <span>
                                    {phoneAccount
                                        ? 'We will text a code to this number'
                                        : 'A new account for this number, confirmed by a code'}
                                </span>
                            </span>
                        </button>
                    </div>

                    <p className="checkout-verify-note">
                        Choosing the mobile number leaves the email off this
                        order, since it belongs to another account.
                    </p>

                    {error && (
                        <p className="checkout-verify-error" role="alert">
                            {error}
                        </p>
                    )}
                </div>
            )}

            {step !== 'choose' && (
                <form
                    id={FORM_ID}
                    className="checkout-verify"
                    onSubmit={submit}
                    noValidate
                >
                    {step === 'code' && (
                        <>
                            <OtpCodeField
                                phone={phone}
                                value={code}
                                onChange={(event) =>
                                    setCode(event.target.value)
                                }
                                error={error}
                                resendSeconds={resendSeconds}
                                onResend={onResend}
                                onEditNumber={onEditNumber}
                                disabled={busy}
                            />

                            {/* For a number that already has an account and
                                a password, a text is not the only way in. */}
                            <button
                                type="button"
                                className="checkout-verify-switch"
                                onClick={onUsePassword}
                                disabled={busy}
                            >
                                Have a password for this number? Sign in with it
                                instead
                            </button>
                        </>
                    )}

                    {step === 'password' && (
                        <>
                            <p className="checkout-verify-intro">
                                Signing in as <strong>{login}</strong>. Your
                                order continues right after.
                            </p>

                            <FormInput
                                id="checkout-verify-password"
                                name="password"
                                type="password"
                                label="Password"
                                icon={Lock}
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                error={error}
                                autoComplete="current-password"
                                disabled={busy}
                                required
                            />

                            {/* A new tab, so the delivery details typed
                                behind this window are still there after. */}
                            <a
                                className="checkout-verify-switch"
                                href={
                                    signingInWithEmail
                                        ? ROUTES.FORGOT_PASSWORD
                                        : ROUTES.FORGOT_PASSWORD_PHONE
                                }
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Forgot password?
                            </a>
                        </>
                    )}
                </form>
            )}
        </Modal>
    );
}
