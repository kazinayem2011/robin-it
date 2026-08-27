/*
 * Autoprefixer only.
 *
 * Tailwind used to be listed here, and the project has never used it: there is
 * not one @tailwind directive in resources/css and not one utility class in the
 * components — the design system is hand-written CSS, as GEMINI.md says it
 * should be. All the plugin did was scan every file on every build to produce
 * nothing.
 */
export default {
    plugins: {
        autoprefixer: {},
    },
};
