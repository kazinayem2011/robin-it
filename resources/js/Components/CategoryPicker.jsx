import React, { useState, useEffect, useRef, useCallback } from 'react';
import axiosInstance from '../services/axiosInstance';
import { API_ENDPOINTS } from '../constants/endpoints';
import { Search, X } from 'lucide-react';

/**
 * Pick a category by typing, not by scrolling.
 *
 * Replaces a native select holding every category in the shop. That was
 * survivable at a hundred and stopped being so at 1,392: the tree was shipped
 * to the browser as a 113 KB Inertia prop on every load of the products screen,
 * purely to fill the dropdown, and a shopkeeper filing a product then had to
 * scroll past a thousand options to find one.
 *
 * Results are fetched as you type, capped server-side, and each carries its
 * ancestry — the names repeat, and "Type-C Cable" under Mobile Accessories is
 * not the same shelf as "Type-C Cable" under Cable.
 *
 * The markup deliberately mirrors FormInput's: same auth-form-group wrapper,
 * auth-input-wrapper, auth-text-input, input-error, auth-field-error and
 * auth-field-hint. A field that sits in the same column as six others and
 * styles its own box and its own error text is the one that looks broken, and
 * this one did — it was reaching for an `auth-input` class the project does not
 * define.
 */
export default function CategoryPicker({
    value,
    onChange,
    label = 'Category',
    required = false,
    error = '',
    // The name and path of the current value, so an edit form can show what is
    // already selected without fetching the whole tree to look it up.
    initialLabel = '',
    /*
     * Multi mode: `value` is an array, choosing appends rather than replaces,
     * and the chosen sit above the box as removable chips. Used for the extra
     * categories a product is listed under, which is the one place a shopkeeper
     * genuinely picks several.
     */
    multiple = false,
    chips = [],
    onRemove,
    placeholder = 'Type to find a category…',
    helperText = '',
    id = 'category-picker-input',
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [chosen, setChosen] = useState(
        value ? { id: value, name: initialLabel, path: '' } : null,
    );

    const boxRef = useRef(null);
    const timer = useRef(null);

    useEffect(() => {
        setChosen(value ? { id: value, name: initialLabel, path: '' } : null);
    }, [value, initialLabel]);

    const fetchResults = useCallback(async (term) => {
        setLoading(true);
        try {
            const res = await axiosInstance.get(
                API_ENDPOINTS.ADMIN.CATEGORY_SEARCH,
                { params: { q: term } },
            );
            setResults(res?.data ?? res ?? []);
        } catch {
            setResults([]);
        } finally {
            setLoading(false);
        }
    }, []);

    // Debounced for the same reason every other search here is: a request per
    // keystroke can land out of order and show the answer to a shorter word.
    useEffect(() => {
        if (!open) return undefined;

        clearTimeout(timer.current);
        timer.current = setTimeout(() => fetchResults(query), 250);

        return () => clearTimeout(timer.current);
    }, [query, open, fetchResults]);

    // Clicking away closes it; without this the list stays over the fields
    // underneath and swallows the next click.
    useEffect(() => {
        const onDocumentClick = (event) => {
            if (boxRef.current && !boxRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDocumentClick);

        return () => document.removeEventListener('mousedown', onDocumentClick);
    }, []);

    const choose = (category) => {
        if (multiple) {
            onChange(category);
            setQuery('');

            // Stays open: picking several is the whole point of this mode, and
            // reopening between each is four extra clicks.
            return;
        }

        setChosen(category);
        onChange(category.id);
        setOpen(false);
        setQuery('');
    };

    return (
        <div className="auth-form-group category-picker" ref={boxRef}>
            {label && (
                <label className="auth-label" htmlFor={id}>
                    {label}{' '}
                    {required && <span className="required-asterisk">*</span>}
                </label>
            )}

            {multiple && chips.length > 0 && (
                <div className="category-picker-chips">
                    {chips.map((chip) => (
                        <span key={chip.id} className="category-picker-chip">
                            {chip.name}
                            <button
                                type="button"
                                onClick={() => onRemove?.(chip.id)}
                                aria-label={`Remove ${chip.name}`}
                            >
                                <X size={11} />
                            </button>
                        </span>
                    ))}
                </div>
            )}

            {!multiple && chosen && !open ? (
                <button
                    type="button"
                    className={`auth-text-input category-picker-chosen ${error ? 'input-error' : ''}`}
                    onClick={() => {
                        setOpen(true);
                        fetchResults('');
                    }}
                >
                    <span>
                        {chosen.path && (
                            <em className="category-picker-path">
                                {chosen.path} ›{' '}
                            </em>
                        )}
                        {chosen.name || `Category #${chosen.id}`}
                    </span>
                    <X
                        size={14}
                        onClick={(e) => {
                            e.stopPropagation();
                            setChosen(null);
                            onChange('');
                        }}
                    />
                </button>
            ) : (
                <div className="auth-input-wrapper">
                    <Search size={18} className="auth-input-icon" />
                    <input
                        id={id}
                        type="text"
                        className={`auth-text-input icon-padded ${error ? 'input-error' : ''}`}
                        autoComplete="off"
                        placeholder={placeholder}
                        value={query}
                        onFocus={() => {
                            setOpen(true);
                            fetchResults(query);
                        }}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>
            )}

            {open && (
                <ul className="category-picker-results">
                    {loading && (
                        <li className="category-picker-empty">Searching…</li>
                    )}

                    {!loading && results.length === 0 && (
                        <li className="category-picker-empty">
                            Nothing matches “{query}”.
                        </li>
                    )}

                    {!loading &&
                        results.map((category) => (
                            <li key={category.id}>
                                <button
                                    type="button"
                                    onClick={() => choose(category)}
                                >
                                    {category.path && (
                                        <em className="category-picker-path">
                                            {category.path} ›{' '}
                                        </em>
                                    )}
                                    {category.name}
                                </button>
                            </li>
                        ))}
                </ul>
            )}

            {error ? (
                <span className="auth-field-error">{error}</span>
            ) : (
                helperText && (
                    <span className="auth-field-hint">{helperText}</span>
                )
            )}
        </div>
    );
}
