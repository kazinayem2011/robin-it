import React, { useEffect, useId, useMemo, useRef, useState } from 'react';
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
 *
 * Announced as what it is, the standard way for a filtered list: the trigger
 * says it opens a list and whether it is open; the search box is a combobox
 * whose highlighted option is read out as the arrows move it; each row is an
 * option that says whether it is chosen; and the number of matches is spoken
 * as they change. It was a button and a text box to a screen reader, with
 * nothing to say what either did or what was in the list.
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
    const triggerRef = useRef(null);

    // Ids for the list and its options, unique on the page.
    const uid = useId().replace(/:/g, '');
    const listId = `${id || name || 'select'}-${uid}-list`;
    const optionId = (index) => `${listId}-${index}`;

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

    // Back to the trigger, so the keyboard carries on from where it was.
    const close = () => {
        setOpen(false);
        triggerRef.current?.focus();
    };

    const choose = (option) => {
        onChange?.({ target: { name, value: option.value } });
        close();
    };

    // Keep the highlighted option in view as the arrows move it.
    useEffect(() => {
        if (!open) return;
        // Only the list scrolls; scrolling the page or a modal would slide a
        // different option under a resting mouse and move the highlight.
        const row = document.getElementById(optionId(highlighted));
        const list = row?.parentElement;
        if (!list) return;
        if (row.offsetTop < list.scrollTop) {
            list.scrollTop = row.offsetTop;
        } else if (
            row.offsetTop + row.offsetHeight >
            list.scrollTop + list.clientHeight
        ) {
            list.scrollTop =
                row.offsetTop + row.offsetHeight - list.clientHeight;
        }
        // optionId only depends on listId, which is stable.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [highlighted, open]);

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
            event.preventDefault();
            close();
        } else if (event.key === 'Tab') {
            setOpen(false);
        }
    };

    // On the closed trigger, the arrow keys open the list, as a select does.
    const onTriggerKeyDown = (event) => {
        if (['ArrowDown', 'ArrowUp'].includes(event.key) && !open) {
            event.preventDefault();
            setOpen(true);
        }
    };

    const resultsText =
        filtered.length === 0
            ? emptyText
            : `${filtered.length}${hiddenCount > 0 ? '+' : ''} ${filtered.length === 1 ? 'result' : 'results'}`;

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
                    ref={triggerRef}
                    type="button"
                    id={id || name}
                    disabled={disabled}
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    aria-controls={open ? listId : undefined}
                    aria-invalid={error ? true : undefined}
                    onKeyDown={onTriggerKeyDown}
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
                                role="combobox"
                                aria-expanded="true"
                                aria-controls={listId}
                                aria-autocomplete="list"
                                aria-activedescendant={
                                    filtered[highlighted]
                                        ? optionId(highlighted)
                                        : undefined
                                }
                                aria-label={
                                    label
                                        ? `Search ${String(label).toLowerCase()}`
                                        : searchPlaceholder
                                }
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

                        {/* How many match, said aloud as it changes. */}
                        <span
                            className="searchable-select-status"
                            role="status"
                            aria-live="polite"
                        >
                            {resultsText}
                        </span>

                        <ul
                            id={listId}
                            role="listbox"
                            aria-label={label ? String(label) : 'Options'}
                            className="searchable-select-list"
                        >
                            {filtered.length === 0 ? (
                                <li
                                    className="searchable-select-empty"
                                    role="presentation"
                                >
                                    {emptyText}
                                </li>
                            ) : (
                                filtered.map((option, index) => {
                                    const isSelected =
                                        String(option.value) === String(value);

                                    return (
                                        <li
                                            key={option.value}
                                            id={optionId(index)}
                                            role="option"
                                            aria-selected={isSelected}
                                            className={`searchable-select-option ${
                                                index === highlighted
                                                    ? 'is-highlighted'
                                                    : ''
                                            } ${isSelected ? 'is-selected' : ''}`}
                                            // Only when the mouse really moves, not when the
                                            // list scrolls under it.
                                            onMouseMove={() =>
                                                index !== highlighted &&
                                                setHighlighted(index)
                                            }
                                            // Chosen on press, before the
                                            // search box loses focus.
                                            onMouseDown={(e) =>
                                                e.preventDefault()
                                            }
                                            onClick={() => choose(option)}
                                        >
                                            <span>{option.label}</span>
                                            {option.hint && (
                                                <span className="searchable-select-hint">
                                                    {option.hint}
                                                </span>
                                            )}
                                        </li>
                                    );
                                })
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

            {error && <span className="form-control-error">{error}</span>}
        </div>
    );
}
