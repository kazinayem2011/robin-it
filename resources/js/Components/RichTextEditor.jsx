import React, { useRef, useEffect, useCallback } from 'react';
import {
    Bold,
    Italic,
    Underline,
    List,
    ListOrdered,
    Heading2,
    Heading3,
    Link2,
    Eraser,
} from 'lucide-react';

/**
 * A small formatting editor for the fields that are stored as HTML.
 *
 * The description reaches the product page through dangerouslySetInnerHTML and
 * has always been able to carry markup — but the admin offered a plain
 * textarea, so the only way to get a bold word or a bullet onto a product page
 * was to type the tags by hand. Nobody does that, which is why every
 * description on the site is one unbroken paragraph.
 *
 * No editor library. The toolbar produces exactly the tags RichText already
 * permits — p, strong, em, u, h2, h3, ul, ol, li, a — and everything written
 * here is purified server-side regardless of what the browser produced.
 *
 * document.execCommand is deprecated and has no replacement with this reach: it
 * is implemented everywhere, understands the selection, and keeps undo working.
 * The alternative is a dependency an order of magnitude larger than this file.
 */

const TOOLS = [
    { cmd: 'bold', icon: Bold, label: 'Bold' },
    { cmd: 'italic', icon: Italic, label: 'Italic' },
    { cmd: 'underline', icon: Underline, label: 'Underline' },
    { cmd: 'formatBlock', arg: 'h2', icon: Heading2, label: 'Heading' },
    { cmd: 'formatBlock', arg: 'h3', icon: Heading3, label: 'Subheading' },
    { cmd: 'insertUnorderedList', icon: List, label: 'Bulleted list' },
    { cmd: 'insertOrderedList', icon: ListOrdered, label: 'Numbered list' },
];

export default function RichTextEditor({
    value = '',
    onChange,
    label,
    helperText = '',
    error = '',
    placeholder = '',
    minHeight = 180,
    id = 'rich-text',
}) {
    const ref = useRef(null);

    /*
     * Written in only when it differs from what is already there.
     *
     * Assigning innerHTML on every render puts the caret back at the start
     * after each keystroke, which makes the field unusable — the classic
     * contentEditable-in-React mistake.
     */
    useEffect(() => {
        const el = ref.current;

        if (el && value !== el.innerHTML) {
            el.innerHTML = value || '';
        }
    }, [value]);

    const emit = useCallback(() => {
        const html = ref.current?.innerHTML ?? '';

        // An empty contentEditable still reports "<br>", which would save as a
        // description that looks blank and is not.
        onChange(html === '<br>' || html === '<div><br></div>' ? '' : html);
    }, [onChange]);

    const run = (tool) => {
        ref.current?.focus();

        if (tool.cmd === 'formatBlock') {
            // Toggle: pressing Heading on a heading returns it to a paragraph.
            const current = document.queryCommandValue('formatBlock');
            document.execCommand(
                'formatBlock',
                false,
                current.toLowerCase() === tool.arg ? 'p' : tool.arg,
            );
        } else {
            document.execCommand(tool.cmd, false, null);
        }

        emit();
    };

    const addLink = () => {
        const url = window.prompt('Link to which address?', 'https://');

        if (!url || url === 'https://') return;

        ref.current?.focus();
        document.execCommand('createLink', false, url);
        emit();
    };

    /*
     * Pasting from a manufacturer's site is how a product description picks up
     * a foreign font stack, a background colour and a table of their layout.
     * Only the text comes in.
     */
    const onPaste = (event) => {
        event.preventDefault();
        const text = event.clipboardData.getData('text/plain');
        document.execCommand('insertText', false, text);
        emit();
    };

    return (
        <div className="auth-form-group">
            {label && (
                <label className="auth-label" htmlFor={id}>
                    {label}
                </label>
            )}

            <div className={`rte ${error ? 'input-error' : ''}`}>
                <div className="rte-toolbar">
                    {TOOLS.map((tool) => (
                        <button
                            key={tool.cmd + (tool.arg || '')}
                            type="button"
                            title={tool.label}
                            aria-label={tool.label}
                            // mousedown, not click: click fires after the
                            // editor has lost focus and the selection with it,
                            // so the command would have nothing to act on.
                            onMouseDown={(e) => {
                                e.preventDefault();
                                run(tool);
                            }}
                        >
                            <tool.icon size={15} />
                        </button>
                    ))}

                    <button
                        type="button"
                        title="Link"
                        aria-label="Link"
                        onMouseDown={(e) => {
                            e.preventDefault();
                            addLink();
                        }}
                    >
                        <Link2 size={15} />
                    </button>

                    <button
                        type="button"
                        title="Clear formatting"
                        aria-label="Clear formatting"
                        onMouseDown={(e) => {
                            e.preventDefault();
                            ref.current?.focus();
                            document.execCommand('removeFormat', false, null);
                            emit();
                        }}
                    >
                        <Eraser size={15} />
                    </button>
                </div>

                <div
                    id={id}
                    ref={ref}
                    className="rte-surface"
                    contentEditable
                    suppressContentEditableWarning
                    role="textbox"
                    aria-multiline="true"
                    aria-label={label || 'Rich text'}
                    data-placeholder={placeholder}
                    style={{ minHeight }}
                    onInput={emit}
                    onBlur={emit}
                    onPaste={onPaste}
                />
            </div>

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
