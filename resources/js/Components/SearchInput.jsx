import React, { useState, useEffect, useRef } from 'react';
import { Search, X } from 'lucide-react';
import { useDebounce } from '@/hooks';

/**
 * Reusable Debounced Search Input Component (SSOT).
 *
 * @param {string} value - Controlled search input value
 * @param {Function} onChange - Triggered immediately on every keystroke
 * @param {Function} onSearch - Debounced callback triggered after delay (e.g. 300ms)
 * @param {number} delay - Debounce delay in milliseconds (default 300ms)
 * @param {string} placeholder - Placeholder text
 * @param {string} className - Additional CSS wrapper classes
 * @param {boolean} clearable - Show clear (X) icon when text is entered
 * @param {boolean} disabled - Disable input
 */
export const SearchInput = ({
    value: controlledValue = '',
    onChange,
    onSearch,
    delay = 300,
    placeholder = 'Search...',
    className = '',
    clearable = true,
    disabled = false,
    autoFocus = false,
    ...props
}) => {
    const [searchTerm, setSearchTerm] = useState(controlledValue);
    const debouncedSearchTerm = useDebounce(searchTerm, delay);

    /*
     * Held in a ref rather than a dependency. Callers pass an inline arrow
     * function, so `onSearch` is a new identity on every render — depending on
     * it made this effect re-run after each response, fire another request, and
     * loop. The admin customers screen was issuing ~47 requests a second.
     */
    const onSearchRef = useRef(onSearch);
    useEffect(() => {
        onSearchRef.current = onSearch;
    });

    // Sync if parent updates controlledValue
    useEffect(() => {
        if (controlledValue !== undefined && controlledValue !== searchTerm) {
            setSearchTerm(controlledValue);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [controlledValue]);

    /*
     * Only search once the term actually changes. Firing on mount would re-request
     * the data the server just rendered.
     */
    const isFirstRun = useRef(true);
    useEffect(() => {
        if (isFirstRun.current) {
            isFirstRun.current = false;
            return;
        }

        onSearchRef.current?.(debouncedSearchTerm);
    }, [debouncedSearchTerm]);

    const handleInputChange = (e) => {
        const val = e.target.value;
        setSearchTerm(val);
        if (onChange) {
            onChange(e);
        }
    };

    const handleClear = () => {
        setSearchTerm('');
        if (onChange) {
            onChange({ target: { value: '' } });
        }
        onSearchRef.current?.('');
    };

    return (
        <div className={`admin-search-wrapper ${className}`.trim()}>
            <Search size={16} className="admin-search-icon" />
            <input
                type="text"
                value={searchTerm}
                onChange={handleInputChange}
                placeholder={placeholder}
                disabled={disabled}
                autoFocus={autoFocus}
                className="admin-search-input"
                {...props}
            />
            {clearable && searchTerm && !disabled && (
                <button
                    type="button"
                    onClick={handleClear}
                    className="admin-search-clear-btn"
                    title="Clear search"
                >
                    <X size={14} />
                </button>
            )}
        </div>
    );
};

export default SearchInput;
