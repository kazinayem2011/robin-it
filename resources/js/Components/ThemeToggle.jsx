import React from 'react';
import { Sun, Moon } from 'lucide-react';
import useAppStore from '../store/useAppStore';
import './ThemeToggle.css';

/**
 * Light or dark.
 *
 * Two states, so this is a switch in the ordinary sense: it shows where you
 * are and one press puts you in the other place. Light is where everybody
 * starts — the machine's own preference is deliberately not consulted, because
 * this is a light shop and a customer's dark laptop should not change what
 * they are shown on a first visit.
 *
 * Two forms, because the two places it appears have very different room:
 *
 *   - the default is a single icon, for the strips it sits in — the top bar
 *     beside Showrooms and the footer. Both are one dense row of small links,
 *     and a label or a menu would be the widest thing in either.
 *   - `inline` lays both out flat, for the phone drawer, which has a whole
 *     panel's width and where the choice needs to be obvious rather than
 *     discovered by pressing something.
 *
 * The icon is the theme you are in, not the one you are going to. The label
 * says what pressing it does, so the two together cannot be misread.
 */

const OPTIONS = [
    { value: 'light', label: 'Light', Icon: Sun },
    { value: 'dark', label: 'Dark', Icon: Moon },
];

export default function ThemeToggle({ variant = 'bar' }) {
    const theme = useAppStore((state) => state.theme);
    const setTheme = useAppStore((state) => state.setTheme);
    const toggleTheme = useAppStore((state) => state.toggleTheme);

    if (variant === 'inline') {
        return (
            <div
                className="theme-toggle-inline"
                role="radiogroup"
                aria-label="Theme"
            >
                {OPTIONS.map(({ value, label, Icon }) => (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={theme === value}
                        className={`theme-toggle-segment${
                            theme === value ? ' is-active' : ''
                        }`}
                        onClick={() => setTheme(value)}
                    >
                        <Icon size={15} />
                        <span>{label}</span>
                    </button>
                ))}
            </div>
        );
    }

    const current = OPTIONS.find((o) => o.value === theme) ?? OPTIONS[0];
    const next = current.value === 'dark' ? OPTIONS[0] : OPTIONS[1];

    /*
     * The name is the whole interface here, so it carries both halves: which
     * theme is on, and what pressing it will do. An icon alone says neither to
     * somebody who cannot see it, and a control labelled only "Theme" is a
     * button whose effect you have to try in order to learn.
     */
    const description = `Theme: ${current.label}. Switch to ${next.label}.`;

    return (
        <button
            type="button"
            className={`theme-toggle-bar theme-toggle-${variant}`}
            onClick={toggleTheme}
            aria-label={description}
            title={description}
        >
            <current.Icon size={15} />
        </button>
    );
}
