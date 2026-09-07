#!/usr/bin/env node
/**
 * Keep the palette out of the components, so both themes keep working.
 *
 * There are two layers of colour in this shop. The *palette* is a fixed ladder
 * — --white, --dark-900, --gray-400, --primary — where each name describes the
 * colour it holds. The *semantic layer* on top of it names the job instead:
 * --bg-surface is "the colour a card sits on", --text-primary is "the colour a
 * heading is written in".
 *
 * Only the semantic layer flips between the light and dark themes. A rule that
 * names a palette token directly therefore keeps its light-theme colour in the
 * dark one, and the failure is silent — near-black text on a near-black card,
 * on whichever page nobody opened. That is not hypothetical: it is exactly what
 * the abandoned prefers-color-scheme block in VariantPickerModal.css did, and
 * what the note in PcBuilder.css was written about.
 *
 * So: inside a property that paints a colour, name the job, not the colour.
 *
 *     color: var(--dark-900)      ->  color: var(--text-primary)
 *     background: var(--white)    ->  background: var(--bg-surface)
 *     color: var(--white)         ->  color: var(--text-on-accent)
 *     border-color: var(--gray-200) -> border-color: var(--border-color)
 *
 * The palette itself is still fair game everywhere else — in a gradient stop
 * on a photographic hero, in the definition of a semantic token, or anywhere a
 * colour is genuinely fixed in both themes.
 *
 * Usage: node scripts/check-theme-tokens.mjs
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const TOKENS = join(ROOT, 'resources/css/app.css');

/* Properties that paint a colour, and so must name a job. */
const THEMED = new Set([
    'color', '-webkit-text-fill-color', 'caret-color', 'fill', 'stroke',
    'text-decoration-color', 'background', 'background-color', 'background-image',
    'border', 'border-color', 'border-top', 'border-right', 'border-bottom',
    'border-left', 'border-top-color', 'border-right-color',
    'border-bottom-color', 'border-left-color', 'outline', 'outline-color',
]);

/* The palette: colour-named rungs that do not move between themes. */
const PALETTE = /var\(\s*--(white|dark-9[0-9]0|dark-[0-9]00|gray-[0-9]+)\s*[,)]/;

/*
 * A narrow, deliberate way out, stated the way check-radius.mjs states its own:
 *
 *     background: var(--white); /* theme-exempt: a white chip on a photo *\/
 */
const EXEMPT = /theme-exempt:/;

const walk = (dir, out = []) => {
    for (const entry of readdirSync(dir)) {
        if (entry === 'node_modules' || entry === 'vendor' || entry[0] === '.') continue;
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) walk(full, out);
        else if (/\.(css|jsx)$/.test(full)) out.push(full);
    }
    return out;
};

const offenders = [];

for (const file of walk(join(ROOT, 'resources'))) {
    const raw = readFileSync(file, 'utf8');

    // Read the markers before comments are blanked, or the blanking erases the
    // very thing being looked for.
    const exempt = new Set(
        raw.split('\n').map((l, i) => (EXEMPT.test(l) ? i + 1 : null)).filter(Boolean),
    );

    // Blank comment bodies, keeping newlines so line numbers still land right.
    let source = raw.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));

    /*
     * app.css defines the semantic layer in terms of the palette, which is the
     * one place that is meant to. Skip its token blocks — everything below
     * them is ordinary component CSS and is checked like any other file.
     */
    if (file === TOKENS) {
        const end = source.indexOf('color-scheme: dark;');
        if (end > 0) source = source.slice(0, end).replace(/[^\n]/g, ' ') + source.slice(end);
    }

    source.split('\n').forEach((line, i) => {
        if (exempt.has(i + 1)) return;

        const decl = line.match(/([-a-zA-Z]+)\s*:\s*(.+)$/);

        if (decl) {
            const [, prop, value] = decl;

            if (
                !prop.startsWith('--') &&
                THEMED.has(prop) &&
                PALETTE.test(value)
            ) {
                offenders.push({
                    file: relative(ROOT, file),
                    line: i + 1,
                    prop,
                    value: value.trim(),
                });
            }
        }

        /*
         * Inline styles in a component, which this used to walk straight past.
         *
         * The quotation sheet wrote `style={{ color: '#16a34a' }}` four times —
         * a green scoring 3.30 against white, under the 4.5 its size needs —
         * and every one sailed through, because the check only ever opened
         * .css files. A colour written in a component is exactly as unthemed
         * as one written in a stylesheet, and rather harder to find later.
         */
        for (const m of line.matchAll(
            /\b(color|background|backgroundColor|borderColor|fill|stroke)\s*:\s*'(#[0-9a-fA-F]{3,8})'/g,
        )) {
            offenders.push({
                file: relative(ROOT, file),
                line: i + 1,
                prop: m[1],
                value: `${m[2]}  (inline style — name a token instead)`,
            });
        }
    });
}

if (!offenders.length) {
    console.log('every themed colour names a job — both themes will follow the tokens');
    process.exit(0);
}

console.error(`${offenders.length} palette colour(s) used where a semantic token belongs:\n`);
for (const o of offenders) console.error(`  ${o.file}:${o.line}\n      ${o.prop}: ${o.value}`);
console.error(
    '\nName the job, not the colour: --text-primary / --text-muted for writing,' +
        '\n--bg-surface / --bg-muted for fills, --border-color for hairlines, and' +
        '\n--text-on-accent for a label on a filled brand colour. They are all' +
        '\ndeclared in resources/css/app.css, next to the dark theme that moves them.',
);
process.exit(1);
