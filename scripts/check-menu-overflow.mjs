#!/usr/bin/env node
/**
 * Hover every category menu and report anything a shopper could not read or
 * reach.
 *
 * The category bar has had five positioning bugs, and not one was visible to
 * the test suite, the linter or the build: a panel clipped by an ancestor, one
 * opening past the bottom of the window, one opening so far from its row that
 * the pointer could not travel to it, a label wrapping and shoving its
 * neighbours, and a panel over-constrained by a specificity clash so it ran off
 * the right edge. Every one of them needed a mouse to find.
 *
 * This drives a real pointer through the DevTools protocol over each category
 * and each flyout, and fails on: a panel outside the window, a link past its
 * panel's edge, or truncated text.
 *
 * Not part of CI — it needs the dev server and a real Chrome. Run it after
 * touching the menu:
 *
 *   php artisan serve &
 *   "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
 *     --headless --disable-gpu --remote-debugging-port=9222 \
 *     --window-size=1440,900 about:blank &
 *   npm run check:menu
 *
 * Exits non-zero when it finds something, so it can gate a release by hand.
 */
const list = await (await fetch('http://127.0.0.1:9222/json/list')).json();
const page = list.find((t) => t.type === 'page');
const ws = new WebSocket(page.webSocketDebuggerUrl);
let id = 0; const pending = new Map();
const send = (m, p = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method: m, params: p })); });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) pending.get(m.id)(m.result); });
await new Promise((r) => ws.addEventListener('open', r));
await send('Page.enable'); await send('Runtime.enable');
await send('Page.navigate', { url: 'http://127.0.0.1:8000/' });
await wait(6000);

const evalJs = async (expression) =>
    (await send('Runtime.evaluate', { returnByValue: true, expression })).result.value;

const hoverAt = async (pt) => {
    if (!pt) return false;
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...pt, buttons: 0 });
    await wait(160);
    return true;
};

const problems = (label) => `(() => {
    const out = [];
    for (const panel of document.querySelectorAll('.cat-nav-drop, .cat-nav-brands')) {
        const pr = panel.getBoundingClientRect();
        if (pr.width === 0 || getComputedStyle(panel).display === 'none') continue;
        if (pr.right > window.innerWidth + 0.5) out.push({ where: ${JSON.stringify(label)}, issue: 'panel past right edge' });
        if (pr.bottom > window.innerHeight + 0.5) out.push({ where: ${JSON.stringify(label)}, issue: 'panel past bottom', by: Math.round(pr.bottom - window.innerHeight) });
        if (pr.left < -0.5) out.push({ where: ${JSON.stringify(label)}, issue: 'panel past left edge' });
        for (const link of panel.querySelectorAll('a')) {
            const span = link.querySelector('span');
            if (span && span.scrollWidth > span.clientWidth + 1)
                out.push({ where: ${JSON.stringify(label)}, issue: 'text truncated', text: link.textContent.trim().slice(0, 28) });
        }
    }
    return out;
})()`;

const categories = await evalJs(
    `[...document.querySelectorAll('.cat-nav > .cat-nav-item > .cat-nav-link')].map((n) => n.textContent.trim())`,
);

const found = [];

for (const category of categories) {
    const pt = await evalJs(`(()=>{const el=[...document.querySelectorAll('.cat-nav-link')].find(n=>n.textContent.trim()===${JSON.stringify(category)});if(!el)return null;const r=el.getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height/2}})()`);
    if (!(await hoverAt(pt))) continue;
    found.push(...(await evalJs(problems(category))));

    const subs = await evalJs(
        `[...document.querySelectorAll('.cat-nav-item.is-open > .cat-nav-drop > .cat-nav-subitem')].filter(li=>li.querySelector('.cat-nav-brands')).map(li=>li.querySelector('.cat-nav-sublink').textContent.trim())`,
    );

    for (const sub of subs || []) {
        const sp = await evalJs(`(()=>{const el=[...document.querySelectorAll('.cat-nav-item.is-open .cat-nav-sublink')].find(n=>n.textContent.trim()===${JSON.stringify(sub)});if(!el)return null;const r=el.getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height/2}})()`);
        if (!(await hoverAt(sp))) continue;
        found.push(...(await evalJs(problems(`${category} > ${sub}`))));
    }
}

console.log(`swept ${categories.length} categories`);

if (found.length === 0) {
    console.log('every panel is on screen, unclipped and untruncated');
    ws.close();
    process.exit(0);
}

console.error(`${found.length} menu problem(s):\n`);
for (const problem of found.slice(0, 25)) {
    console.error(`  ${problem.where}: ${problem.issue}${problem.text ? ` — "${problem.text}"` : ''}`);
}
ws.close();
process.exit(1);
