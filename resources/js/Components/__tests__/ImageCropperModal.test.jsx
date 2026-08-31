import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import ImageCropperModal from '../ImageCropperModal';

/**
 * Choosing a file inside the cropper, and having it survive the next render.
 *
 * Nothing could be uploaded through this modal. `acceptedTypes` defaulted to an
 * inline array, so it was rebuilt on every render; that gave
 * validateAndProcessFile a new identity every render, which re-ran the effect
 * that loads the image on every render. Every caller in the admin opens the
 * modal empty and lets the user browse from inside it, so `imageSrc` is null
 * throughout — and the effect's first branch was `setImageObj(null)`.
 *
 * So: pick a file, the reader loads it, imageObj is set, React re-renders, the
 * effect runs again and puts it straight back to null. No canvas, and
 * "Apply & Crop" disabled for good.
 */
describe('ImageCropperModal file picking', () => {
    beforeEach(() => {
        /*
         * jsdom has no decoder: an <img> never fires load, so the component
         * would stall regardless of the bug under test. Fire it on assignment.
         */
        vi.stubGlobal(
            'Image',
            class {
                constructor() {
                    this.width = 800;
                    this.height = 600;
                    setTimeout(() => this.onload?.(), 0);
                }
                set src(_v) {}
                get src() {
                    return 'blob:stub';
                }
            },
        );

        vi.stubGlobal('URL', {
            ...URL,
            createObjectURL: () => 'blob:stub',
            revokeObjectURL: () => {},
        });

        /*
         * jsdom implements no 2D context at all, and the component draws on
         * every state change. A proxy answers whatever it reaches for; the
         * drawing itself is not what these tests are about.
         */
        HTMLCanvasElement.prototype.getContext = vi.fn(
            () =>
                new Proxy(
                    {},
                    {
                        get: (target, prop) => {
                            if (prop === 'canvas')
                                return { width: 0, height: 0 };
                            if (!(prop in target)) target[prop] = vi.fn();
                            return target[prop];
                        },
                        set: () => true,
                    },
                ),
        );

        HTMLCanvasElement.prototype.toBlob = vi.fn((cb) =>
            cb(new Blob(['x'], { type: 'image/jpeg' })),
        );
        HTMLCanvasElement.prototype.toDataURL = vi.fn(
            () => 'data:image/jpeg;base64,x',
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    const open = (props = {}) =>
        render(
            <ImageCropperModal
                isOpen
                onClose={() => {}}
                onCropComplete={() => {}}
                aspectRatio={1}
                title="Crop Product Image (1:1)"
                {...props}
            />,
        );

    const pick = (file) => {
        const input = document.querySelector('input[type="file"]');
        expect(input).toBeTruthy();
        fireEvent.change(input, { target: { files: [file] } });
    };

    /** The whole bug: the file is chosen, and then thrown away. */
    it('keeps the chosen file and enables the crop button', async () => {
        open();

        const applyBefore = screen.getByRole('button', {
            name: /apply & crop/i,
        });
        expect(applyBefore).toBeDisabled();

        pick(new File(['x'], 'shot.png', { type: 'image/png' }));

        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: /apply & crop/i }),
            ).not.toBeDisabled();
        });
    });

    /** It survives a re-render, which is what actually destroyed it. */
    it('survives a re-render while the modal stays open', async () => {
        const { rerender } = open();

        pick(new File(['x'], 'shot.png', { type: 'image/png' }));

        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: /apply & crop/i }),
            ).not.toBeDisabled();
        });

        rerender(
            <ImageCropperModal
                isOpen
                onClose={() => {}}
                onCropComplete={() => {}}
                aspectRatio={1}
                title="Crop Product Image (1:1)"
            />,
        );

        expect(
            screen.getByRole('button', { name: /apply & crop/i }),
        ).not.toBeDisabled();
    });

    it('refuses a file that is not one of the accepted types', async () => {
        open();

        pick(new File(['x'], 'notes.pdf', { type: 'application/pdf' }));

        expect(
            await screen.findByText(/unsupported file type/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /apply & crop/i }),
        ).toBeDisabled();
    });

    /** Closing empties it, so the next thing cropped does not open onto the last. */
    it('starts empty the next time it opens', async () => {
        const { rerender } = open();

        pick(new File(['x'], 'shot.png', { type: 'image/png' }));
        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: /apply & crop/i }),
            ).not.toBeDisabled();
        });

        rerender(
            <ImageCropperModal
                isOpen={false}
                onClose={() => {}}
                onCropComplete={() => {}}
            />,
        );
        rerender(
            <ImageCropperModal
                isOpen
                onClose={() => {}}
                onCropComplete={() => {}}
                aspectRatio={1}
            />,
        );

        expect(
            screen.getByRole('button', { name: /apply & crop/i }),
        ).toBeDisabled();
    });
});
