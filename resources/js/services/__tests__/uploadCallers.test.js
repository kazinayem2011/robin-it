import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

/**
 * uploadImage resolves to the whole payload — { path, disk_path, name, size } —
 * so a caller has to take `path` out of it.
 *
 * Seven screens did. The brands screen did not, and assigned the object itself
 * to logo_path: the upload succeeded and said so, and then saving was rejected
 * with "The logo path field must be a string". Nobody connected the two, so
 * every one of the twenty-eight brands still has no logo and the mega menu
 * draws a lettermark for all of them — which is exactly what that screen was
 * built to fix.
 *
 * It is a one-word difference in a line that looks right, which is why this is
 * a test over the callers rather than a note on the service.
 */
describe('uploadImage callers', () => {
    const walk = (dir, out = []) => {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            if (entry.name === '__tests__') continue;
            const full = join(dir, entry.name);
            if (entry.isDirectory()) walk(full, out);
            else if (/\.jsx?$/.test(entry.name)) out.push(full);
        }
        return out;
    };

    const CALL =
        /(?:const|let)\s+(\{\s*path[^}]*\}|\w+)\s*=\s*await\s+uploadService\.uploadImage\(/g;

    it('every caller destructures the path out of the payload', () => {
        const offenders = [];

        for (const file of walk('resources/js')) {
            const source = readFileSync(file, 'utf8');

            for (const [, binding] of source.matchAll(CALL)) {
                if (!binding.startsWith('{')) {
                    offenders.push(`${file}: const ${binding} = await …`);
                }
            }
        }

        expect(offenders).toEqual([]);
    });

    it('finds the callers at all, so the check cannot pass vacuously', () => {
        const found = walk('resources/js').flatMap((file) => [
            ...readFileSync(file, 'utf8').matchAll(CALL),
        ]);

        expect(found.length).toBeGreaterThanOrEqual(8);
    });
});
