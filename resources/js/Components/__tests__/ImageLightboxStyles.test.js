import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/*
 * The photo viewer carries its own styles.
 *
 * They lived in the product page's sheet, so opened from the admin product
 * list it had none and fell apart into bare images and buttons.
 */
const read = (path) => readFileSync(resolve(process.cwd(), path), 'utf8');

describe('ImageLightbox styles', () => {
    it('imports its own stylesheet', () => {
        expect(read('resources/js/Components/ImageLightbox.jsx')).toContain(
            "import './ImageLightbox.css';",
        );
    });

    it('keeps its rules there, not on one page', () => {
        expect(read('resources/js/Components/ImageLightbox.css')).toContain(
            '.lightbox-backdrop',
        );
        expect(read('resources/js/Pages/Products/Show.css')).not.toContain(
            '.lightbox-',
        );
    });
});
