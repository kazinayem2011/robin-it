import React from 'react';

/**
 * The colour an option is, as a round swatch beside its name.
 *
 * Set on the option in the product form. Decorative: the name beside it says
 * the same thing in words, so it is hidden from screen readers.
 */
export default function OptionSwatch({ color }) {
    if (!color) return null;

    return (
        <span
            className="option-swatch"
            style={{ '--swatch': color }}
            aria-hidden="true"
        />
    );
}
