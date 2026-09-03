import React, {
    useCallback,
    useEffect,
    useId,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown, Check, Search, X } from 'lucide-react';
import './Select.css';

/**
 * A dropdown the shop draws itself.
 *
 * The closed control was never the problem — a global `select` rule already
 * gave every one of them our height, border and chevron. What looked foreign
 * was the list that opens: that popup is drawn by the operating system, and no
 * stylesheet in any browser can reach it. On a Mac it is a Mac list, on Windows
 * a Windows one, and in the middle of a page that is otherwise ours it reads as
 * borrowed. The only way to style it is to stop using `<select>`.
 *
 * So this is the whole control: a trigger that keeps looking like the input it
 * replaced, and a panel we own.
 *
 * Three things it does that the native one could not, and one it must not lose:
 *
 * - The panel is portalled to the body and positioned fixed. Native popups
 *   escape their parent; an absolutely positioned div does not, and half of
 *   these live inside a scrolling modal or a card with `overflow: hidden`,
 *   which would have clipped the list to a sliver.
 * - Past a handful of options it grows a search box, because scrolling to find
 *   one of forty is the thing native lists are worst at.
 * - On a phone it opens as a bottom sheet with full-width rows. The reason to
 *   keep a native select is the OS wheel picker's big touch targets, so this
 *   gives that back rather than shrinking the desktop panel onto a phone.
 *
 * The thing it must not lose is the keyboard: type to jump, arrows to move,
 * Enter or Space to choose, Escape to close, Home and End for the ends. A
 * custom listbox that skips this is strictly worse than what it replaced.
 *
 * The props mirror FormSelect's, so it drops into either shape: pass `label`
 * (and optionally `formik`) for a full form field, or leave it off and get the
 * bare control for a toolbar, with the page's own `<label for>` still pointing
 * at it.
 */

/** `['Dhaka']` and `[{ value, label }]` are both fair ways to write a list. */
const normalise = (options) =>
    (options || []).map((opt) =>
        opt && typeof opt === 'object'
            ? {
                  ...opt,
                  value: opt.value,
                  label: opt.label ?? String(opt.value),
              }
            : { value: opt, label: String(opt) },
    );

/* Roughly where a native list stops being scannable and starts being a haystack. */
const SEARCH_FROM = 8;

const PHONE = '(max-width: 640px)';

export default function Select({
    label,
    id,
    name,
    value,
    onChange,
    onBlur,
    options = [],
    placeholder = '',
    error = '',
    helperText = '',
    required = false,
    disabled = false,
    className = '',
    groupClassName = '',
    formik = null,
    /** null decides by length; true or false settles it. */
    searchable = null,
    searchPlaceholder = 'Type to search…',
    emptyText = 'Nothing matches that.',
    'aria-label': ariaLabel,
    ...rest
}) {
    // Formik integration, matching FormSelect so the two are interchangeable.
    const fieldName = name || id;
    const fieldValue = formik ? formik.values?.[fieldName] : value;
    const fieldError = formik
        ? formik.touched?.[fieldName] && formik.errors?.[fieldName]
        : error;

    const fireChange = formik ? formik.handleChange : onChange;
    const fireBlur = formik ? formik.handleBlur : onBlur;

    const items = useMemo(() => normalise(options), [options]);

    /*
     * With a label this is a form field, so it takes the same box as every
     * other one unless the caller has a class of its own. Without a label it
     * is a bare control in a toolbar, where `auth-text-input`'s full width
     * would be wrong.
     */
    const triggerClass = className || (label ? 'auth-text-input' : '');

    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [active, setActive] = useState(-1);
    const [phone, setPhone] = useState(
        () => typeof window !== 'undefined' && window.matchMedia(PHONE).matches,
    );
    const [pos, setPos] = useState(null);

    const triggerRef = useRef(null);
    const panelRef = useRef(null);
    const searchRef = useRef(null);
    const listRef = useRef(null);
    const typed = useRef({ buffer: '', at: 0 });

    const reactId = useId();
    const controlId = id || fieldName || `select-${reactId}`;
    const panelId = `${controlId}-listbox`;

    const useSearch = searchable ?? items.length > SEARCH_FROM;

    const selected = items.find(
        (o) => String(o.value) === String(fieldValue ?? ''),
    );

    const shown = useMemo(() => {
        const needle = term.trim().toLowerCase();

        if (!needle) return items;

        return items.filter((o) => o.label.toLowerCase().includes(needle));
    }, [items, term]);

    /* Follow the window across the breakpoint — a tablet turned sideways
       crosses it, and the panel and the sheet are different components. */
    useEffect(() => {
        const mq = window.matchMedia(PHONE);
        const onChangeMq = (e) => setPhone(e.matches);

        mq.addEventListener('change', onChangeMq);

        return () => mq.removeEventListener('change', onChangeMq);
    }, []);

    const close = useCallback(({ refocus = true } = {}) => {
        setOpen(false);
        setTerm('');
        if (refocus) triggerRef.current?.focus();
    }, []);

    /*
     * Where the panel goes.
     *
     * Fixed to the viewport rather than parked under the trigger in the DOM,
     * because the trigger's ancestors clip: the address picker sits in a card,
     * the division picker in a scrolling modal, the category picker inside a
     * search bar with rounded corners and `overflow: hidden`.
     */
    const place = useCallback(() => {
        const el = triggerRef.current;

        if (!el) return;

        const r = el.getBoundingClientRect();
        const gap = 6;
        const below = window.innerHeight - r.bottom - gap;
        const above = r.top - gap;
        // Drop upward only when down genuinely will not do, so the list opens
        // in the same direction nearly everywhere.
        const up = below < 220 && above > below;

        setPos({
            top: up ? undefined : r.bottom + gap,
            bottom: up ? window.innerHeight - r.top + gap : undefined,
            left: Math.max(
                8,
                Math.min(r.left, window.innerWidth - r.width - 8),
            ),
            width: r.width,
            maxHeight: Math.max(160, Math.min(320, up ? above : below)),
        });
    }, []);

    useLayoutEffect(() => {
        if (!open || phone) return undefined;

        place();

        // Capture phase: a scroll inside the modal never bubbles to the window.
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);

        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open, phone, place]);

    /*
     * Escape closes the list and stops there.
     *
     * Caught on the way down, at the window, because Modal listens for Escape
     * on the window too — and it is the outer listener, so a bubbling Escape
     * reached it as well and shut the whole dialog. Opening the division
     * picker in the address book and pressing Escape closed the address form.
     * Capture phase runs before any of that, so nothing else sees the key.
     */
    useEffect(() => {
        if (!open) return undefined;

        const onEscape = (e) => {
            if (e.key !== 'Escape') return;

            e.stopPropagation();
            e.preventDefault();
            close();
        };

        window.addEventListener('keydown', onEscape, true);

        return () => window.removeEventListener('keydown', onEscape, true);
    }, [open, close]);

    // A click anywhere else closes it. The panel is portalled, so "else" means
    // outside both the trigger and the panel, not outside one wrapper.
    useEffect(() => {
        if (!open) return undefined;

        const onDown = (e) => {
            if (
                triggerRef.current?.contains(e.target) ||
                panelRef.current?.contains(e.target)
            ) {
                return;
            }

            setOpen(false);
            setTerm('');
            // A click elsewhere is the user leaving the field, so Formik hears
            // about it the way a native blur would have told it.
            fireBlur?.({ target: { name: fieldName, id: controlId } });
        };

        document.addEventListener('mousedown', onDown);

        return () => document.removeEventListener('mousedown', onDown);
    }, [open, fireBlur, fieldName, controlId]);

    // Open on the current choice, so the first arrow key moves from where you
    // are rather than from the top of the list.
    useEffect(() => {
        if (!open) return;

        const at = items.findIndex(
            (o) => String(o.value) === String(fieldValue ?? ''),
        );

        setActive(at);

        if (useSearch) searchRef.current?.focus();
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (term) setActive(shown.length ? 0 : -1);
    }, [term, shown.length]);

    // Keep the highlighted row on screen while arrowing through a long list.
    useEffect(() => {
        if (!open || active < 0) return;

        listRef.current
            ?.querySelector(`[data-index="${active}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [active, open]);

    const choose = (option) => {
        if (option.disabled) return;

        fireChange?.({
            target: {
                name: fieldName,
                id: controlId,
                value: option.value,
                type: 'select-one',
            },
        });

        close();
    };

    /* Typing a letter jumps to the next option starting with it, the way a
       native select has always behaved. Only where there is no search box —
       with one, the letters belong in it. */
    const typeahead = (key) => {
        const now = Date.now();
        const buffer =
            now - typed.current.at > 700 ? key : typed.current.buffer + key;

        typed.current = { buffer, at: now };

        const from = active + (buffer.length > 1 ? 0 : 1);
        const order = items.map((_, i) => (from + i) % items.length);
        const hit = order.find((i) =>
            items[i].label.toLowerCase().startsWith(buffer.toLowerCase()),
        );

        if (hit === undefined) return;

        if (open) setActive(hit);
        else choose(items[hit]);
    };

    const step = (delta) => {
        const list = open ? shown : items;

        if (!list.length) return;

        // Skip anything that cannot be picked rather than parking on it.
        let next = active;

        for (let i = 0; i < list.length; i += 1) {
            next =
                next < 0
                    ? delta > 0
                        ? 0
                        : list.length - 1
                    : (next + delta + list.length) % list.length;

            if (!list[next]?.disabled) break;
        }

        setActive(next);
    };

    const onKeyDown = (e) => {
        if (disabled) return;

        switch (e.key) {
            case 'ArrowDown':
            case 'ArrowUp':
                e.preventDefault();
                if (!open) setOpen(true);
                else step(e.key === 'ArrowDown' ? 1 : -1);
                break;
            case 'Home':
            case 'End':
                if (!open) break;
                e.preventDefault();
                setActive(e.key === 'Home' ? 0 : shown.length - 1);
                break;
            case 'Enter':
                e.preventDefault();
                if (!open) setOpen(true);
                else if (shown[active]) choose(shown[active]);
                break;
            case ' ':
                // Space types a space in the search box; elsewhere it opens.
                if (open && useSearch) break;
                e.preventDefault();
                if (!open) setOpen(true);
                else if (shown[active]) choose(shown[active]);
                break;
            case 'Tab':
                if (open) close({ refocus: false });
                break;
            default:
                if (
                    !useSearch &&
                    e.key.length === 1 &&
                    !e.metaKey &&
                    !e.ctrlKey
                ) {
                    e.preventDefault();
                    typeahead(e.key);
                }
        }
    };

    const rows = (
        <ul
            className="ui-select-list"
            id={panelId}
            role="listbox"
            ref={listRef}
            aria-label={ariaLabel || label || 'Options'}
        >
            {shown.length === 0 ? (
                <li className="ui-select-empty">{emptyText}</li>
            ) : (
                shown.map((option, index) => {
                    const isSelected =
                        String(option.value) === String(fieldValue ?? '');

                    return (
                        <li key={`${option.value}`} role="presentation">
                            <button
                                type="button"
                                id={`${panelId}-opt-${index}`}
                                data-index={index}
                                role="option"
                                aria-selected={isSelected}
                                disabled={option.disabled}
                                className={`ui-select-option${
                                    index === active ? ' is-active' : ''
                                }${isSelected ? ' is-selected' : ''}`}
                                onMouseMove={() => setActive(index)}
                                onClick={() => choose(option)}
                            >
                                <span className="ui-select-option-text">
                                    <span>{option.label}</span>
                                    {option.hint && (
                                        <small>{option.hint}</small>
                                    )}
                                </span>

                                {/* The tick, not a coloured row alone: on a
                                    filtered list the highlighted row and the
                                    chosen row are rarely the same one. */}
                                {isSelected && <Check size={15} />}
                            </button>
                        </li>
                    );
                })
            )}
        </ul>
    );

    const search = useSearch && (
        <div className="ui-select-search">
            <Search size={15} />
            <input
                ref={searchRef}
                type="text"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                onKeyDown={onKeyDown}
                placeholder={searchPlaceholder}
                aria-label={searchPlaceholder}
                aria-controls={panelId}
                autoComplete="off"
            />
            {term && (
                <button
                    type="button"
                    onClick={() => {
                        setTerm('');
                        searchRef.current?.focus();
                    }}
                    aria-label="Clear search"
                >
                    <X size={14} />
                </button>
            )}
        </div>
    );

    const panel =
        open &&
        typeof document !== 'undefined' &&
        createPortal(
            phone ? (
                <div className="ui-select-sheet-backdrop">
                    <div
                        className="ui-select-panel is-sheet"
                        ref={panelRef}
                        onKeyDown={onKeyDown}
                    >
                        <div className="ui-select-sheet-head">
                            <strong>{label || placeholder || 'Choose'}</strong>
                            <button
                                type="button"
                                onClick={() => close()}
                                aria-label="Close"
                            >
                                <X size={18} />
                            </button>
                        </div>
                        {search}
                        {rows}
                    </div>
                </div>
            ) : (
                <div
                    className="ui-select-panel"
                    ref={panelRef}
                    onKeyDown={onKeyDown}
                    style={
                        pos
                            ? {
                                  top: pos.top,
                                  bottom: pos.bottom,
                                  left: pos.left,
                                  width: pos.width,
                                  maxHeight: pos.maxHeight,
                              }
                            : // Off-screen until measured, so the first paint
                              // is never in the top-left corner.
                              { visibility: 'hidden' }
                    }
                >
                    {search}
                    {rows}
                </div>
            ),
            document.body,
        );

    const control = (
        <>
            <button
                type="button"
                id={controlId}
                ref={triggerRef}
                disabled={disabled}
                role="combobox"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? panelId : undefined}
                aria-activedescendant={
                    open && active >= 0 ? `${panelId}-opt-${active}` : undefined
                }
                aria-label={ariaLabel}
                aria-invalid={fieldError ? true : undefined}
                aria-required={required || undefined}
                className={`ui-select-trigger${
                    fieldError ? ' input-error' : ''
                }${selected ? '' : ' is-empty'} ${triggerClass}`.trim()}
                onClick={() => (open ? close() : setOpen(true))}
                onKeyDown={onKeyDown}
                {...rest}
            >
                <span className="ui-select-value">
                    {selected ? selected.label : placeholder}
                </span>
                <ChevronDown size={16} className="ui-select-chevron" />
            </button>

            {/* So a plain, non-Formik form still posts the value the way the
                select it replaced did. */}
            <input type="hidden" name={fieldName} value={fieldValue ?? ''} />

            {panel}
        </>
    );

    if (!label) return <div className="ui-select">{control}</div>;

    return (
        <div
            className={`auth-form-group ui-select-field ${groupClassName}`.trim()}
        >
            <label className="auth-label" htmlFor={controlId}>
                {label}{' '}
                {required && <span className="required-asterisk">*</span>}
            </label>

            {control}

            {fieldError ? (
                <span className="auth-field-error">{fieldError}</span>
            ) : (
                helperText && (
                    <span className="auth-field-hint">{helperText}</span>
                )
            )}
        </div>
    );
}
