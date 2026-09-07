/**
 * Which theme the site is wearing, and how it is remembered.
 *
 * Two choices, and light is the one a visitor gets until they say otherwise —
 * the machine's own preference is deliberately not consulted. That is a shop
 * decision rather than a technical one: the storefront is a light shop, and a
 * dark laptop should not silently change what a customer sees on their first
 * visit. Dark is something you choose here, and once chosen it sticks.
 *
 * Nothing here throws. Storage can be unavailable — a private window, site
 * data switched off — and a shop that will not render because it could not
 * read a preference is a far worse outcome than one that renders light.
 */

const KEY = 'robinit.theme.v1';

export const THEMES = ['light', 'dark'];

export const DEFAULT_THEME = 'light';

/**
 * The stored theme, or light for anyone who has never picked one.
 *
 * Anything unrecognised falls back rather than being trusted — which also
 * quietly retires the 'system' this once stored, so a visitor carrying that
 * value from an earlier visit lands on light instead of on nothing.
 */
export const readThemeChoice = () => {
    try {
        const stored = window.localStorage.getItem(KEY);

        return THEMES.includes(stored) ? stored : DEFAULT_THEME;
    } catch {
        return DEFAULT_THEME;
    }
};

export const writeThemeChoice = (choice) => {
    if (!THEMES.includes(choice)) return;

    try {
        window.localStorage.setItem(KEY, choice);
    } catch {
        // Quota, or storage switched off. The theme still applies for this
        // page; it just will not be remembered on the next one.
    }
};

/**
 * Stamp the theme onto <html>.
 *
 * The attribute is the single source of truth the stylesheet selects on, and
 * the colour-scheme keyword beside it is what makes the browser's *own*
 * painting follow — scrollbars, the caret, a date picker's calendar, form
 * controls nobody has styled. Without it the page goes dark and the scrollbar
 * beside it stays a bright light-mode strip.
 */
export const applyTheme = (choice) => {
    const theme = THEMES.includes(choice) ? choice : DEFAULT_THEME;
    const root = document.documentElement;

    root.setAttribute('data-theme', theme);
    root.style.colorScheme = theme;

    /*
     * The colour a phone paints its status bar and the browser its chrome. It
     * has to be the page's own background or the app looks like it stops an
     * inch below the top of the screen.
     */
    const meta = document.querySelector('meta[name="theme-color"]');

    if (meta) {
        meta.setAttribute('content', theme === 'dark' ? '#0a0e17' : '#f1f4f8');
    }

    return theme;
};

export default applyTheme;
