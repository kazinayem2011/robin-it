import React from 'react';
import { Loader2 } from 'lucide-react';

/**
 * Universal Reusable Button Component (Vanilla CSS Design System)
 * @param {'primary'|'secondary'|'danger'|'dark'|'outline'|'outline-white'|'ghost'} variant
 * @param {'sm'|'md'|'lg'} size
 */
export const Button = ({
    children,
    type = 'button',
    variant = 'primary',
    size = 'md',
    fullWidth = false,
    loading = false,
    disabled = false,
    icon: Icon = null,
    iconPosition = 'left',
    className = '',
    onClick,
    ...props
}) => {
    const variantClass = `btn-${variant}`;
    const sizeClass = size === 'md' ? '' : `btn-${size}`;
    const widthClass = fullWidth ? 'btn-full' : '';
    const stateClass = disabled || loading ? 'btn-disabled' : '';

    return (
        <button
            {...props}
            type={type}
            disabled={disabled || loading}
            onClick={onClick}
            className={`btn ${variantClass} ${sizeClass} ${widthClass} ${stateClass} ${className}`.trim()}
        >
            {loading && (
                <Loader2
                    size={size === 'sm' ? 14 : 16}
                    className="btn-spinner animate-spin"
                />
            )}
            {!loading && Icon && iconPosition === 'left' && (
                <Icon size={size === 'sm' ? 14 : 16} />
            )}
            {children && <span>{children}</span>}
            {!loading && Icon && iconPosition === 'right' && (
                <Icon size={size === 'sm' ? 14 : 16} />
            )}
        </button>
    );
};

export default Button;
