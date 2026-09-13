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
        /*
         * Every class in the selector, not only the one being styled.
         *
         * `.account-identity-missing a` styles the links inside that item, so
         * the class is load-bearing — delete it and the links lose their
         * rules — but it is never the last segment, and reading only the last
         * segment reported it, and a dozen more like it, as styling nothing.
         * That is what put this check at 96 standing failures, which is the
         * same as no check at all.
         */
        for (const part of selector.split(',')) {
            for (const m of part.matchAll(/\.(-?[A-Za-z_][\w-]*)/g)) {
                if (!defined.has(m[1])) defined.set(m[1], relative(ROOT, file));
            }
        }
    }
}

/* ---- classes the markup uses -------------------------------------------- */
const used = new Map();
const record = (name, file, line) => {
    /*
     * A CSS identifier, or it is not a class name.
     *
     * Nested template literals are what this is really for. In
     * `` `card ${n > 0 ? `tone-${t}` : 'clear'}` `` the inner backtick ends
     * the literal early, leaving `${n`, `>`, `0` and `?` to be split off as
     * words — and three of those were being reported as missing styles for
     * rules named `.0`, `.>` and `.?`.
     */
    if (!/^-?[A-Za-z_][\w-]*$/.test(name || '')) return;
    if (!used.has(name)) used.set(name, new Set());
    used.get(name).add(`${relative(ROOT, file)}:${line}`);
};

/**
 * The text of one `className={...}`, from the opening brace to the brace that
 * closes it.
 *
 * A lazy `\{([\s\S]*?)\}` stops at the first `}` in the file, which for
 * `` `cmp-${r.status === 'sent' ? 'sent' : r.status}` `` is the one closing
 * the hole — so the expression arrived cut in half, its trailing backtick
 * gone, and every rule below read a fragment. Braces nest through strings,
 * template literals and the holes inside them, so they have to be counted
 * rather than matched.
 */
const readBraced = (source, open) => {
    // Each entry is what we are inside: '{' an expression, '`' a template,
    // or the quote character of a plain string.
    const stack = ['{'];
    let i = open + 1;

    while (i < source.length && stack.length) {
        const ch = source[i];
        const top = stack[stack.length - 1];

        if (ch === '\\' && top !== '{') {
            i += 2;
            continue;
        }
        if (top === "'" || top === '"') {
            if (ch === top) stack.pop();
            i++;
            continue;
        }
        if (top === '`') {
            if (ch === '`') stack.pop();
            else if (ch === '$' && source[i + 1] === '{') {
                stack.push('{');
                i++;
            }
            i++;
            continue;
        }
        if (ch === "'" || ch === '"' || ch === '`') stack.push(ch);
        else if (ch === '{') stack.push('{');
        else if (ch === '}') stack.pop();
        i++;
    }

    return { text: source.slice(open + 1, i - 1), end: i };
};

/**
 * The class names an expression can be sure of.
 *
 * `${...}` holes are the whole difficulty. `${on ? 'is-open' : ''}` names a
 * class and must be read; `badge-${tone}` names one that only exists at run
 * time and cannot be checked against any stylesheet — and reading its pieces
 * reported a rule called `.badge-`. So a hole is cut out, and any token it
 * was glued to goes with it, while the hole's own expression is read in turn.
 */
const HOLE = '\u0001';

const closingQuote = (text, open) => {
    for (let i = open + 1; i < text.length; i++) {
        if (text[i] === '\\') i++;
        else if (text[i] === text[open]) return i;
    }
    return text.length;
};

/* A template from its opening backtick: static text with holes punched out. */
const readTemplate = (text, open) => {
    const holes = [];
    let statics = '';
    let i = open + 1;

    for (; i < text.length && text[i] !== '`'; i++) {
        if (text[i] === '\\') {
            statics += text[i + 1] ?? '';
            i++;
            continue;
        }
        if (text[i] === '$' && text[i + 1] === '{') {
            const { text: inner, end } = readBraced(text, i + 1);
            // Numbered, so the token test below can tell which hole it found.
            statics += `${HOLE}${holes.length}${HOLE}`;
            holes.push(inner);
            i = end - 1;
            continue;
        }
        statics += text[i];
    }

    return { statics, holes, end: i };
};

const scanExpression = (text, file, line) => {
    /*
     * Comparison operands first. `activeTab === 'orders' ? 'active' : ''`
     * mentions 'orders', but that is the value being tested, and counting it
     * reported a page's own tab names as missing styles. Either side counts,
     * and a closing paren may sit between: the tab strips are written
     * `(filters.status || 'all') === tab.key`.
     */
    const expr = text
        .replace(/[!=]==?\s*\(*\s*(?:'[^']*'|"[^"]*")/g, ' ')
        .replace(/(?:'[^']*'|"[^"]*")\s*\)*\s*[!=]==?/g, ' ');

    const take = (chunk) => {
        for (const c of chunk.split(/\s+/)) {
            if (!c.includes(HOLE)) record(c, file, line);
        }
    };

    for (let i = 0; i < expr.length; i++) {
        const ch = expr[i];

        if (ch === "'" || ch === '"') {
            const close = closingQuote(expr, i);
            take(expr.slice(i + 1, close));
            i = close;
            continue;
        }
        if (ch === '`') {
            const { statics, holes, end } = readTemplate(expr, i);
            take(statics);

            /*
             * Only a hole that is a whole class on its own. In
             * `${on ? 'is-open' : ''}` the branches are class names; in
             * `cmp-${s === 'sent' ? 'sent' : s}` they are the tail of one,
             * and reading them reported a rule called `.sent` that should
             * have been `.cmp-sent`.
             */
            const tokens = statics.split(/\s+/);
            holes.forEach((hole, index) => {
                if (tokens.includes(`${HOLE}${index}${HOLE}`)) {
                    scanExpression(hole, file, line);
                }
            });
            i = end;
        }
    }
};

for (const file of jsxFiles) {
    const source = readFileSync(file, 'utf8');
    const lineOf = (index) => source.slice(0, index).split('\n').length;

    for (const m of source.matchAll(/className\s*=\s*(?:"([^"]*)"|'([^']*)'|(\{))/g)) {
        const line = lineOf(m.index);

        /*
         * Checked against undefined, not for truthiness. `className = ''` in a
         * destructured signature is a match with an empty capture, and reading
         * that as "no literal here" sent the brace reader off from a quote
         * character to the end of the file, collecting every word on the way.
         */
        if (m[1] !== undefined || m[2] !== undefined) {
            for (const c of (m[1] ?? m[2]).split(/\s+/)) record(c, file, line);
            continue;
        }

        scanExpression(readBraced(source, m.index + m[0].length - 1).text, file, line);
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
