import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

/**
 * Reusable TextInput component (Vanilla CSS Design System)
 */
export default forwardRef(function TextInput(
    {
        type = 'text',
        className = '',
        isFocused = false,
        hasError = false,
        ...props
    },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <input
            {...props}
            type={type}
            className={`form-control-input ${hasError ? 'has-error' : ''} ${className}`.trim()}
            ref={localRef}
        />
    );
});
