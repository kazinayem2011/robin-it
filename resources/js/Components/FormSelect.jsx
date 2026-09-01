import React from 'react';

/**
 * Reusable FormSelect component with Formik integration, label, options list/children, and error display (DRY & SSOT).
 */
export const FormSelect = ({
    label,
    id,
    name,
    value,
    onChange,
    onBlur,
    options = [],
    placeholder = '',
    error = '',
    helperText = '',
    required = false,
    disabled = false,
    className = '',
    formik = null,
    children,
    ...props
}) => {
    // Automatic Formik Integration (DRY Helper)
    const fieldName = name || id;
    const fieldValue = formik ? formik.values?.[fieldName] : value;
    const fieldOnChange = formik ? formik.handleChange : onChange;
    const fieldOnBlur = formik ? formik.handleBlur : onBlur;
    const fieldError = formik
        ? formik.touched?.[fieldName] && formik.errors?.[fieldName]
        : error;

    return (
        <div className={`auth-form-group ${className}`.trim()}>
            {label && (
                <label className="auth-label" htmlFor={id || name}>
                    {label}{' '}
                    {required && <span className="required-asterisk">*</span>}
                </label>
            )}

            <select
                id={id || name}
                name={fieldName}
                value={fieldValue ?? ''}
                onChange={fieldOnChange}
                onBlur={fieldOnBlur}
                disabled={disabled}
                className={`auth-text-input ${fieldError ? 'input-error' : ''}`}
                {...props}
            >
                {placeholder && <option value="">{placeholder}</option>}
                {options && options.length > 0
                    ? options.map((opt) => {
                          const val = typeof opt === 'object' ? opt.value : opt;
                          const lbl = typeof opt === 'object' ? opt.label : opt;
                          return (
                              <option key={val} value={val}>
                                  {lbl}
                              </option>
                          );
                      })
                    : children}
            </select>

            {/*
             * A hint under the field, the same as FormInput's. Without the
             * prop declared here it fell through to ...props and was spread
             * onto the <select>, where React dropped it as an unknown
             * attribute — written and never seen.
             */}
            {fieldError ? (
                <span className="auth-field-error">{fieldError}</span>
            ) : (
                helperText && (
                    <span className="auth-field-hint">{helperText}</span>
                )
            )}
        </div>
    );
};

export default FormSelect;
