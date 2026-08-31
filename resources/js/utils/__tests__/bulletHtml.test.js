import { describe, it, expect } from 'vitest';
import { bulletsToLines, linesToBullets } from '../bulletHtml';

/**
 * Key Features is stored as markup and typed as lines. These two halves have to
 * agree, or editing a product silently rewrites its features.
 */
describe('bulletHtml', () => {
    it('turns typed lines into a list', () => {
        expect(linesToBullets('RAM: 16GB\nSSD: 512GB')).toBe(
            '<ul><li>RAM: 16GB</li><li>SSD: 512GB</li></ul>',
        );
    });

    it('drops blank lines rather than making empty bullets', () => {
        expect(linesToBullets('A\n\n  \nB')).toBe(
            '<ul><li>A</li><li>B</li></ul>',
        );
    });

    it('stores nothing when nothing was typed', () => {
        expect(linesToBullets('   \n  ')).toBe('');
    });

    it('reads a stored list back as lines', () => {
        expect(
            bulletsToLines('<ul><li>RAM: 16GB</li><li>SSD: 512GB</li></ul>'),
        ).toBe('RAM: 16GB\nSSD: 512GB');
    });

    /** Round trip, which is what an edit actually does. */
    it('survives a round trip', () => {
        const typed = 'Processor: Core i5-13420H\nGraphics: RTX 3050';
        expect(bulletsToLines(linesToBullets(typed))).toBe(typed);
    });

    /**
     * Features written before this field took lines were free HTML. They come
     * back as text rather than vanishing on the next save.
     */
    it('recovers text from markup that was never a list', () => {
        expect(bulletsToLines('<p>Fast and quiet</p>')).toBe('Fast and quiet');
    });

    it('is safe with nothing stored', () => {
        expect(bulletsToLines('')).toBe('');
        expect(bulletsToLines(null)).toBe('');
    });

    /** A shopkeeper typing "16GB > 8GB" must not produce broken markup. */
    it('escapes characters that would otherwise be markup', () => {
        expect(linesToBullets('16GB > 8GB & faster')).toBe(
            '<ul><li>16GB &gt; 8GB &amp; faster</li></ul>',
        );
    });
});
