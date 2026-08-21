import React from 'react';
import Button from './Button';

export default function PrimaryButton({
    className = '',
    disabled = false,
    children,
    ...props
}) {
    return (
        <Button
            {...props}
            variant="primary"
            disabled={disabled}
            className={className}
        >
            {children}
        </Button>
    );
}
