import React from 'react';

/**
 * Reusable InputError component (Vanilla CSS Design System)
 */
export default function InputError({ message, className = '', ...props }) {
    return message ? (
        <span {...props} className={`form-control-error ${className}`.trim()}>
            {message}
        </span>
    ) : null;
}
