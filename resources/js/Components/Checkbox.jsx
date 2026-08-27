import React from 'react';

/**
 * A checkbox as a form field: its own row, with a label beside it.
 *
 * There are two ways to get the design system's checkbox, and which one fits
 * depends on the layout rather than on taste:
 *
 *   This component, for forms — it brings the row wrapper and the label with
 *   it, which is what a settings panel or a modal wants.
 *
 *   The `custom-checkbox-input` class on a plain <input>, for everywhere the
 *   surrounding element is already the affordance: the shop's filter rows, the
 *   PC builder's compatibility toggle, the coupon scope chips. Those wrap the
 *   input in their own <label> so the whole chip or row is clickable, and
 *   nesting this component inside would put a second wrapper in the way and
 *   shrink the hit area to the tick itself.
 *
 * What must not happen is a third way. A bare <input type="checkbox"> renders
 * the browser's own control — a blue tick on a red-accented site — which is
 * what those three were doing.
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
