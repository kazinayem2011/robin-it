#!/usr/bin/env node
/**
 * Find class names the markup uses that no stylesheet defines.
 *
 * This project styles with plain CSS files, so a class name in JSX that has no
 * matching rule fails silently — the element simply renders unstyled. Neither
 * the PHP suite nor Vitest can see it, and ESLint has no reason to care, so it
 * only ever surfaces when somebody opens the page and notices. Nine separate
 * bugs in one working session came from exactly this: a share dialog with no
 * dialog, a builder thumbnail that grew to 662px, form fields 20px tall.
 *
 * Reports what is used and undefined. Run with --unused to also list rules
 * nobody references, which is how those names drift apart in the first place —
 * markup gets renamed and the stylesheet is left behind.
 *
 * Usage: node scripts/find-unstyled-classes.mjs [--unused] [--json]
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

// fileURLToPath, not URL.pathname: this project lives under a directory with a
// space in its name, which pathname hands back percent-encoded.
const ROOT = fileURLToPath(new URL('..', import.meta.url));
const walk = (dir, out = []) => {
    for (const entry of readdirSync(dir)) {
        if (entry === 'node_modules' || entry === 'vendor' || entry[0] === '.') continue;
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) walk(full, out);
        else out.push(full);
    }
    return out;
};

const files = walk(join(ROOT, 'resources'));
const jsxFiles = files.filter((f) => ['.jsx', '.js'].includes(extname(f)));
const cssFiles = files.filter((f) => extname(f) === '.css');

/* ---- classes the stylesheets define ------------------------------------- */
const defined = new Map();
for (const file of cssFiles) {
    const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    // Selector position only: ignore anything inside a declaration block.
    for (const block of css.split('}')) {
        const selector = block.split('{')[0];
        if (!selector) continue;
        for (const m of selector.matchAll(/\.(-?[A-Za-z_][\w-]*)/g)) {
            if (!defined.has(m[1])) defined.set(m[1], relative(ROOT, file));
        }
    }
}

/* ---- classes the markup uses -------------------------------------------- */
const used = new Map();
const record = (name, file, line) => {
    if (!name || /[${}`]/.test(name)) return;
    // Route strings and the like are not class names.
    if (name.includes('/') || name.includes('.')) return;
    if (!used.has(name)) used.set(name, new Set());
    used.get(name).add(`${relative(ROOT, file)}:${line}`);
};

for (const file of jsxFiles) {
    const source = readFileSync(file, 'utf8');
    const lineOf = (index) => source.slice(0, index).split('\n').length;

    // className="..." and className={...} in any form; take every string
    // literal inside the expression so ternaries and template holes count.
    for (const m of source.matchAll(/className\s*=\s*(?:"([^"]*)"|'([^']*)'|\{([\s\S]*?)\})/g)) {
        const line = lineOf(m.index);
        if (m[1] ?? m[2]) {
            for (const c of (m[1] ?? m[2]).split(/\s+/)) record(c, file, line);
            continue;
        }
        /*
         * Strip comparison operands first. `activeTab === 'orders' ? 'active'
         * : ''` mentions 'orders', but that is the value being tested, not a
         * class, and counting it reported a page's own tab names as missing
         * styles.
         */
        const expr = (m[3] ?? '').replace(/[!=]==?\s*(?:'[^']*'|"[^"]*")/g, ' ');
        for (const lit of expr.matchAll(/`([^`]*)`|'([^']*)'|"([^"]*)"/g)) {
            const text = lit[1] ?? lit[2] ?? lit[3] ?? '';
            // Drop ${...} holes, keep the literal segments around them.
            for (const c of text.replace(/\$\{[^}]*\}/g, ' ').split(/\s+/)) {
                record(c, file, line);
            }
        }
    }
}

/* ---- report -------------------------------------------------------------- */
const unstyled = [...used.keys()].filter((c) => !defined.has(c)).sort();
const unused = [...defined.keys()].filter((c) => !used.has(c)).sort();

if (process.argv.includes('--json')) {
    console.log(JSON.stringify({ unstyled, unused }, null, 2));
} else {
    console.log(`\n${unstyled.length} class names used in markup with no CSS rule:\n`);
    for (const name of unstyled) {
        const where = [...used.get(name)].slice(0, 2).join(', ');
        console.log(`  .${name}\n      ${where}`);
    }
    if (process.argv.includes('--unused')) {
        console.log(`\n${unused.length} rules defined but never used:\n`);
        for (const name of unused) console.log(`  .${name}  (${defined.get(name)})`);
    }
    console.log(
        `\nscanned ${jsxFiles.length} markup files and ${cssFiles.length} stylesheets; ` +
            `${used.size} classes used, ${defined.size} defined.\n`,
    );
}

process.exit(unstyled.length > 0 ? 1 : 0);
