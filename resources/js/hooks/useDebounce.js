import { useState, useEffect, useCallback, useRef } from 'react';

/**
 * Custom hook to debounce a fast-changing value.
 *
 * @param {any} value - The input value to debounce
 * @param {number} delay - Debounce delay in milliseconds (default: 300ms)
 * @returns {any} The debounced value
 */
export function useDebounce(value, delay = 300) {
    const [debouncedValue, setDebouncedValue] = useState(value);

    useEffect(() => {
        const handler = setTimeout(() => {
            setDebouncedValue(value);
        }, delay);

        return () => {
            clearTimeout(handler);
        };
    }, [value, delay]);

    return debouncedValue;
}

/**
 * Custom hook to create a debounced callback function.
 *
 * @param {Function} callback - The callback to debounce
 * @param {number} delay - Debounce delay in milliseconds (default: 300ms)
 * @returns {Function} The debounced function
 */
export function useDebouncedCallback(callback, delay = 300) {
    const timeoutRef = useRef(null);

    const debouncedFn = useCallback(
        (...args) => {
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
            }
            timeoutRef.current = setTimeout(() => {
                callback(...args);
            }, delay);
        },
        [callback, delay],
    );

    useEffect(() => {
        return () => {
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
            }
        };
    }, []);

    return debouncedFn;
}

export default useDebounce;
