import React, { useCallback, useEffect, useRef, useState } from 'react';
import { RotateCw } from 'lucide-react';
import FormInput from './FormInput';
import './OtpCodeField.css';

/**
 * The step where somebody types the code they were just texted.
 *
 * Shared between sign-up and password reset because the awkward parts are the
 * same in both, and getting them wrong in one place is enough to make the whole
 * flow feel broken:
 *
 *   The number is shown back. A code that never arrives is nearly always a
 *   mistyped number, and the customer cannot spot that unless they can see it.
 *
 *   Resend is a countdown, not a button that fails. The server refuses inside a
 *   minute, so an enabled button is a promise the shop will not keep.
 *
 *   Pasted codes are cleaned rather than rejected. Phones offer the code with
 *   a trailing space, and some people paste the whole message.
 */
export default function OtpCodeField({
    phone,
    value,
    onChange,
    onBlur,
    error,
    onResend,
    onEditNumber,
    resendSeconds = 60,
    disabled = false,
    name = 'code',
}) {
    const [waiting, setWaiting] = useState(resendSeconds);
    const [resending, setResending] = useState(false);
    const [note, setNote] = useState('');
    const inputRef = useRef(null);

    useEffect(() => {
        if (waiting <= 0) return undefined;

        const tick = setInterval(() => setWaiting((s) => s - 1), 1000);
        return () => clearInterval(tick);
    }, [waiting]);

    // The code is the only thing being asked for, so put the cursor in it.
    useEffect(() => {
        inputRef.current?.querySelector('input')?.focus();
    }, []);

    const handleResend = useCallback(async () => {
        if (waiting > 0 || resending) return;

        setResending(true);
        setNote('');

        try {
            await onResend();
            setWaiting(resendSeconds);
            setNote('A new code is on its way.');
        } catch (err) {
            setNote(
                err?.message ||
                    'Could not send another code. Try again shortly.',
            );
        } finally {
            setResending(false);
        }
    }, [waiting, resending, onResend, resendSeconds]);

    /*
     * Only digits, and only six of them.
     *
     * A phone's autofill hands over the code with a space on the end, and
     * people paste the whole sentence it arrived in. Both are obviously the
     * right code, so they are cleaned rather than refused.
     */
    const handleChange = (event) => {
        const digits = (event.target.value || '')
            .replace(/\D/g, '')
            .slice(0, 6);

        onChange({ target: { name, value: digits } });
    };

    return (
        <div className="otp-step">
            <div className="otp-step-head">
                <p className="otp-step-sent">
                    We sent a six-digit code to <strong>{phone}</strong>.
                </p>
                {onEditNumber && (
                    <button
                        type="button"
                        className="otp-step-edit"
                        onClick={onEditNumber}
                    >
                        Change number
                    </button>
                )}
            </div>

            <div ref={inputRef}>
                <FormInput
                    id={name}
                    name={name}
                    required
                    label="Verification code"
                    type="text"
                    value={value}
                    onChange={handleChange}
                    onBlur={onBlur}
                    /*
                     * No placeholder, and no icon.
                     *
                     * "000000" at this tracking is indistinguishable from six
                     * typed digits, so the field looks filled in when it is
                     * empty. The icon costs the field its centre — with one on
                     * the left the code sits off to the right, which for six
                     * characters on their own is the only thing holding the
                     * layout together.
                     */
                    error={error}
                    disabled={disabled}
                    className="otp-step-input"
                    inputMode="numeric"
                    /*
                     * Lets both iOS and Android offer the code straight from
                     * the notification, which is the difference between typing
                     * six digits and tapping once.
                     */
                    autoComplete="one-time-code"
                    maxLength={6}
                />
            </div>

            <div className="otp-step-resend">
                {waiting > 0 ? (
                    <span className="otp-step-countdown">
                        You can ask for another code in {waiting}s
                    </span>
                ) : (
                    <button
                        type="button"
                        className="otp-step-resend-button"
                        onClick={handleResend}
                        disabled={resending}
                    >
                        <RotateCw size={14} />
                        {resending ? 'Sending…' : 'Send another code'}
                    </button>
                )}
            </div>

            {note && <p className="otp-step-note">{note}</p>}
        </div>
    );
}
