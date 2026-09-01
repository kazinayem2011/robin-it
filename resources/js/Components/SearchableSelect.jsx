import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, Search, X } from 'lucide-react';

/**
 * A select you can type into.
 *
 * A native dropdown is fine for five options and unusable for a catalogue: a
 * shop with a thousand products cannot be scrolled to find one. Filtering is
 * debounced so typing does not re-filter on every keystroke, and the list is
 * capped so a broad match cannot render a thousand rows into the DOM.
 *
 * Keyboard: type to filter, arrows to move, Enter to choose, Escape to close.
 */
export default function SearchableSelect({
    label,
    value,
    onChange,
    options = [],
    placeholder = 'Choose…',
    searchPlaceholder = 'Type to search…',
    /*
     * Given, the caller does the searching and this stops filtering what it
     * was handed. A list capped server-side — the stock picker is fifty of a
     * thousand products — cannot be narrowed by filtering the fifty.
     */
    onSearch = null,
    emptyText = 'Nothing matches that.',
    disabled = false,
    debounceMs = 200,
    maxVisible = 50,
    required = false,
    error = '',
    name,
    id,
}) {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [debounced, setDebounced] = useState('');
    const [highlighted, setHighlighted] = useState(0);

    const rootRef = useRef(null);
    const searchRef = useRef(null);

    // Debounce the filter so a fast typist does not re-filter per keystroke.
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(term), debounceMs);

        return () => clearTimeout(timer);
    }, [term, debounceMs]);

    // Close when the click lands outside.
    useEffect(() => {
        if (!open) return;

        const onDocumentClick = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDocumentClick);

        return () => document.removeEventListener('mousedown', onDocumentClick);
    }, [open]);

    useEffect(() => {
        if (open) searchRef.current?.focus();
        else setTerm('');
    }, [open]);

    // Ask the caller for a new list when the term settles, rather than
    // filtering the one already in hand.
    useEffect(() => {
        if (onSearch) onSearch(debounced.trim());
    }, [debounced, onSearch]);

    const filtered = useMemo(() => {
        const needle = debounced.trim().toLowerCase();
        const matches =
            needle && !onSearch
                ? options.filter((o) => o.label.toLowerCase().includes(needle))
                : options;

        return matches.slice(0, maxVisible);
    }, [options, debounced, maxVisible, onSearch]);

    const hiddenCount = useMemo(() => {
        const needle = debounced.trim().toLowerCase();
        const total =
            needle && !onSearch
                ? options.filter((o) => o.label.toLowerCase().includes(needle))
                      .length
                : options.length;

        return Math.max(0, total - filtered.length);
    }, [options, debounced, filtered.length, onSearch]);

    useEffect(() => setHighlighted(0), [debounced]);

    const selected = options.find((o) => String(o.value) === String(value));

    const choose = (option) => {
        onChange?.({ target: { name, value: option.value } });
        setOpen(false);
    };

    const onKeyDown = (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlighted((i) => Math.min(i + 1, filtered.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlighted((i) => Math.max(i - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (filtered[highlighted]) choose(filtered[highlighted]);
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <div className="auth-form-group" ref={rootRef}>
            {label && (
                <label className="auth-label" htmlFor={id || name}>
                    {label}{' '}
                    {required && <span className="required-asterisk">*</span>}
                </label>
            )}

            <div className="searchable-select">
                <button
                    type="button"
                    id={id || name}
                    disabled={disabled}
                    className={`auth-text-input searchable-select-trigger ${
                        error ? 'input-error' : ''
                    }`}
                    onClick={() => setOpen((v) => !v)}
                >
                    <span
                        className={
                            selected ? '' : 'searchable-select-placeholder'
                        }
                    >
                        {selected ? selected.label : placeholder}
                    </span>
                    <ChevronDown size={16} />
                </button>

                {open && (
                    <div className="searchable-select-panel">
                        <div className="searchable-select-search">
                            <Search size={15} />
                            <input
                                ref={searchRef}
                                type="text"
                                value={term}
                                onChange={(e) => setTerm(e.target.value)}
                                onKeyDown={onKeyDown}
                                placeholder={searchPlaceholder}
                            />
                            {term && (
                                <button
                                    type="button"
                                    onClick={() => setTerm('')}
                                    aria-label="Clear search"
                                >
                                    <X size={14} />
                                </button>
                            )}
                        </div>

                        <ul className="searchable-select-list">
                            {filtered.length === 0 ? (
                                <li className="searchable-select-empty">
                                    {emptyText}
                                </li>
                            ) : (
                                filtered.map((option, index) => (
                                    <li key={option.value}>
                                        <button
                                            type="button"
                                            className={`searchable-select-option ${
                                                index === highlighted
                                                    ? 'is-highlighted'
                                                    : ''
                                            } ${
                                                String(option.value) ===
                                                String(value)
                                                    ? 'is-selected'
                                                    : ''
                                            }`}
                                            onMouseEnter={() =>
                                                setHighlighted(index)
                                            }
                                            onClick={() => choose(option)}
                                        >
                                            <span>{option.label}</span>
                                            {option.hint && (
                                                <span className="searchable-select-hint">
                                                    {option.hint}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))
                            )}
                        </ul>

                        {hiddenCount > 0 && (
                            <div className="searchable-select-more">
                                {hiddenCount} more — keep typing to narrow it
                                down.
                            </div>
                        )}
                    </div>
                )}
            </div>

            {error && <span className="auth-error-text">{error}</span>}
        </div>
    );
}
