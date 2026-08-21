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

            {fieldError && (
                <span className="auth-field-error">{fieldError}</span>
            )}
        </div>
    );
};

export default FormSelect;
