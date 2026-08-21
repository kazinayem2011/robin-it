import React, { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';

/**
 * Reusable FormInput component with Formik integration, icons, BD mobile badge, and error displays (DRY & SSOT).
 */
export const FormInput = ({
    label,
    id,
    name,
    type = 'text',
    value,
    onChange,
    onBlur,
    placeholder = '',
    icon: Icon = null,
    isBdPhone = false,
    error = '',
    required = false,
    autoComplete = '',
    disabled = false,
    className = '',
    style = {},
    rows = 3,
    formik = null,
    ...props
}) => {
    const [showPassword, setShowPassword] = useState(false);
    const isPassword = type === 'password';
    const isTextarea = type === 'textarea';
    const inputType = isPassword ? (showPassword ? 'text' : 'password') : type;

    // Automatic Formik Integration (DRY Helper)
    const fieldName = name || id;
    const fieldValue = formik ? formik.values?.[fieldName] : value;
    const fieldOnChange = formik ? formik.handleChange : onChange;
    const fieldOnBlur = formik ? formik.handleBlur : onBlur;
    const fieldError = formik
        ? formik.touched?.[fieldName] && formik.errors?.[fieldName]
        : error;

    return (
        <div className={`auth-form-group ${className}`.trim()} style={style}>
            {label && (
                <label className="auth-label" htmlFor={id || name}>
                    {label}{' '}
                    {required && <span className="required-asterisk">*</span>}
                </label>
            )}

            <div className="auth-input-wrapper">
                {Icon && !isBdPhone && (
                    <Icon size={18} className="auth-input-icon" />
                )}

                {isBdPhone && (
                    <div className="phone-prefix-box">
                        <span className="bd-flag">🇧🇩</span>
                        <span className="prefix-text">+880</span>
                    </div>
                )}

                {isTextarea ? (
                    <textarea
                        id={id || name}
                        name={fieldName}
                        rows={rows}
                        value={fieldValue ?? ''}
                        onChange={fieldOnChange}
                        onBlur={fieldOnBlur}
                        placeholder={placeholder}
                        disabled={disabled}
                        className={`auth-text-input ${fieldError ? 'input-error' : ''}`}
                        {...props}
                    ></textarea>
                ) : (
                    <input
                        id={id || name}
                        name={fieldName}
                        type={inputType}
                        value={fieldValue ?? ''}
                        onChange={fieldOnChange}
                        onBlur={fieldOnBlur}
                        placeholder={placeholder}
                        autoComplete={autoComplete}
                        disabled={disabled}
                        className={`auth-text-input ${isBdPhone ? 'phone-padded' : ''} ${Icon && !isBdPhone ? 'icon-padded' : ''} ${isPassword ? 'password-padded' : ''} ${fieldError ? 'input-error' : ''}`}
                        {...props}
                    />
                )}

                {isPassword && (
                    <button
                        type="button"
                        className="password-toggle-btn"
                        onClick={() => setShowPassword(!showPassword)}
                        tabIndex={-1}
                        aria-label={
                            showPassword ? 'Hide password' : 'Show password'
                        }
                    >
                        {showPassword ? (
                            <EyeOff size={18} />
                        ) : (
                            <Eye size={18} />
                        )}
                    </button>
                )}
            </div>

            {fieldError && (
                <span className="auth-field-error">{fieldError}</span>
            )}
        </div>
    );
};

export default FormInput;
