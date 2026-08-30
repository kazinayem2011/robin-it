import React from 'react';

/**
 * The mark beside a brand in the menu.
 *
 * A drawn icon cannot say "ASUS". Putting the same generic box next to eleven
 * hundred brand names is noise dressed as information — it takes up the space
 * where a distinguishing mark should be and distinguishes nothing.
 *
 * So: the brand's own logo where the shop has uploaded one, and a lettermark
 * everywhere else. A lettermark is genuinely useful — "MS" in one colour and
 * "AS" in another are told apart at a glance, which is the whole job of an icon
 * in a list this long.
 */

/*
 * Ten hues spread around the wheel, picked by hashing the name so a brand keeps
 * the same colour on every page and between deploys. Random would re-colour
 * ASUS on every render and make the menu flicker.
 *
 * Stated as HSL parts rather than finished colours so the same hue can be used
 * at two lightnesses — a legible foreground on a tinted ground — without
 * maintaining two lists that can drift apart.
 */
const HUES = [3, 28, 45, 96, 152, 174, 199, 232, 268, 315];

const hueFor = (name) => {
    let hash = 0;

    for (let i = 0; i < name.length; i++) {
        hash = (hash << 5) - hash + name.charCodeAt(i);
        hash |= 0;
    }

    return HUES[Math.abs(hash) % HUES.length];
};

/**
 * Up to two characters, and only from the start of words.
 *
 * "Cooler Master" reads as CM, "ASUS" as AS, "1STPLAYER" as 1S. Taking the
 * first two letters of a single word rather than the first letter alone keeps
 * ASUS and Antec apart, which one letter would not.
 */
const initialsFor = (name) => {
    const words = name
        .trim()
        .split(/[\s-]+/)
        .filter(Boolean);

    if (words.length === 0) {
        return '?';
    }

    if (words.length === 1) {
        return words[0].slice(0, 2).toUpperCase();
    }

    return (words[0][0] + words[1][0]).toUpperCase();
};

export default function BrandMark({ name = '', logo = null, size = 18 }) {
    if (logo) {
        return (
            <img
                src={logo}
                // Decorative: the brand name is already the link text beside it,
                // so announcing it twice only slows a screen reader down.
                alt=""
                aria-hidden="true"
                className="brand-mark brand-mark-logo"
                width={size}
                height={size}
                loading="lazy"
            />
        );
    }

    const hue = hueFor(name);

    return (
        <span
            className="brand-mark brand-mark-letters"
            aria-hidden="true"
            style={{
                width: size,
                height: size,
                background: `hsl(${hue} 70% 92%)`,
                color: `hsl(${hue} 65% 32%)`,
                fontSize: Math.round(size * 0.42),
            }}
        >
            {initialsFor(name)}
        </span>
    );
}
