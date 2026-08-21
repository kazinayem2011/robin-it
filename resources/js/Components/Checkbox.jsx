import React from 'react';

/**
 * Reusable Checkbox Component (Vanilla CSS Design System)
 */
export const Checkbox = ({
    id,
    name,
    label,
    checked,
    onChange,
    disabled = false,
    className = '',
    error = '',
    ...props
}) => {
    return (
        <div className={`custom-checkbox-group ${className}`.trim()}>
            <label className="custom-checkbox-wrapper" htmlFor={id || name}>
                <input
                    {...props}
                    type="checkbox"
                    id={id || name}
                    name={name}
                    checked={checked}
                    onChange={onChange}
                    disabled={disabled}
                    className="custom-checkbox-input"
                />
                {label && (
                    <span className="custom-checkbox-label">{label}</span>
                )}
            </label>
            {error && <span className="form-control-error">{error}</span>}
        </div>
    );
};

export default Checkbox;
