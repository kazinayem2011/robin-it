import React from 'react';
import Button from './Button';

export default function SecondaryButton({
    className = '',
    disabled = false,
    children,
    ...props
}) {
    return (
        <Button
            {...props}
            variant="secondary"
            disabled={disabled}
            className={className}
        >
            {children}
        </Button>
    );
}
