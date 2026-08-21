import React from 'react';
import Button from './Button';

export default function DangerButton({
    className = '',
    disabled = false,
    children,
    ...props
}) {
    return (
        <Button
            {...props}
            variant="danger"
            disabled={disabled}
            className={className}
        >
            {children}
        </Button>
    );
}
