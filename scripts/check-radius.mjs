#!/usr/bin/env node
/**
 * Keep corner rounding on one value.
 *
 * Every card, button, input, select and dropdown in this shop is meant to be
 * rounded 8px. That is expressed as --radius-* tokens, all of which resolve to
 * 8px, plus --radius-full for the deliberate pills. Nothing enforces it, so a
 * literal `border-radius: 6px` typed into any of the twenty-five stylesheets
 * renders slightly wrong and nobody notices until the page is next opened next
 * to another one — which is exactly how 6px, 10px and 9999px got in.
 *
 * This flags every hardcoded border-radius outside the small set of values
 * that are legitimately not tokens. Blade is exempt: email clients cannot
 * resolve CSS variables, so those templates must carry the literal.
 *
 * Usage: node scripts/check-radius.mjs
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

// fileURLToPath, not URL.pathname: this project lives under a directory with a
// space in its name, which pathname hands back percent-encoded.
const ROOT = fileURLToPath(new URL('..', import.meta.url));

/*
 * 0 squares a corner off deliberately; 50% is a circle (avatars, icon buttons);
 * inherit/unset are pass-through. Everything else should be a token.
 */
const ALLOWED = new Set(['0', '0px', '50%', 'inherit', 'initial', 'unset', 'revert']);

/*
 * A narrow, deliberate way out.
 *
 * The rule is about chrome — cards, buttons, inputs — and chart geometry is not
 * chrome. A 3px-wide bar capped at the 8px token is not a bar any more, so the
 * choice was either to distort a chart to satisfy a rule about panels, or to
 * let the exception be stated. It has to be stated: the comment carries a
 * reason, so the next person reads why rather than assuming an oversight.
 *
 *     border-radius: 2px 2px 0 0; // radius-exempt: chart bar cap
 */
const EXEMPT = /radius-exempt:/;

const walk = (dir, out = []) => {
    for (const entry of readdirSync(dir)) {
        if (entry === 'node_modules' || entry === 'vendor' || entry[0] === '.') continue;
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) walk(full, out);
        else out.push(full);
    }
    return out;
};

const targets = walk(join(ROOT, 'resources')).filter((f) =>
    ['.css', '.jsx', '.js'].includes(extname(f)),
);

// `border-radius`, the per-corner longhands, and the JSX inline `borderRadius`.
const DECL = /(?:border(?:-[a-z]+)?-radius|borderRadius)\s*:\s*([^;}\n,]+)/g;

const offenders = [];

for (const file of targets) {
    /*
     * Blank out comment bodies before scanning, keeping the newlines so line
     * numbers still point at the right place. Without this the prose in
     * app.css explaining the scale reads as a declaration and reports itself.
     */
    const raw = readFileSync(file, 'utf8');

    // Read the markers before comments are blanked, or the blanking erases the
    // very thing being looked for.
    const exempt = new Set(
        raw
            .split('\n')
            .map((line, i) => (EXEMPT.test(line) ? i + 1 : null))
            .filter(Boolean),
    );

    const source = raw.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    const lines = source.split('\n');

    lines.forEach((line, i) => {
        for (const match of line.matchAll(DECL)) {
            const value = match[1].trim().replace(/^['"]|['"]$/g, '');

            // A shorthand sets up to four corners; judge each one on its own.
            const corners = value.split(/\s+(?![^(]*\))/).filter(Boolean);
            const bad = corners.filter(
                (c) => !c.startsWith('var(--radius') && !ALLOWED.has(c),
            );

            if (bad.length && !exempt.has(i + 1)) {
                offenders.push({
                    file: relative(ROOT, file),
                    line: i + 1,
                    value,
                });
            }
        }
    });
}

if (!offenders.length) {
    console.log(
        `corner rounding is consistent — ${targets.length} files, every border-radius is a --radius token`,
    );
    process.exit(0);
}

console.error(`${offenders.length} hardcoded border-radius value(s):\n`);
for (const o of offenders) {
    console.error(`  ${o.file}:${o.line}\n      border-radius: ${o.value}`);
}
console.error(
    '\nUse var(--radius-md) for cards, buttons, inputs, selects and dropdowns,' +
        '\nor var(--radius-full) for a pill. Both live in resources/css/app.css.',
);
process.exit(1);
