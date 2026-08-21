import React from 'react';

/**
 * Reusable InputLabel component (Vanilla CSS Design System)
 */
export default function InputLabel({
    value,
    className = '',
    required = false,
    children,
    ...props
}) {
    return (
        <label {...props} className={`form-control-label ${className}`.trim()}>
            {value ? value : children}
            {required && <span className="required-asterisk">*</span>}
        </label>
    );
}
