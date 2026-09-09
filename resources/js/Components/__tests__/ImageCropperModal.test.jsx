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
    /**
     * The shape of the file it writes.
     *
     * The output canvas was sized from the width and height inputs, which
     * start at targetWidth/targetHeight and know nothing about the ratio. A
     * caller asking for 4:3 with the default 800x800 output had its selection
     * squashed into a square by the non-uniform scale in the export — so every
     * product photo cropped since 4:3 was introduced came out stretched, and
     * nothing on the page could have shown otherwise.
     */
    it('writes the file at the ratio it cropped at', async () => {
        const canvases = [];
        const realCreate = document.createElement.bind(document);
        vi.spyOn(document, 'createElement').mockImplementation(
            (tag, ...rest) => {
                const el = realCreate(tag, ...rest);
                if (tag === 'canvas') canvases.push(el);
                return el;
            },
        );

        open({ aspectRatio: 4 / 3, lockAspect: true, targetWidth: 1200 });
        pick(new File(['x'], 'shot.png', { type: 'image/png' }));

        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: /apply & crop/i }),
            ).not.toBeDisabled();
        });

        fireEvent.click(screen.getByRole('button', { name: /apply & crop/i }));

        // The export builds its own detached canvas; the visible one is the
        // preview and is sized by the layout.
        const out = canvases.at(-1);
        expect(out.width).toBe(1200);
        expect(out.height).toBe(900);
        expect(out.width / out.height).toBeCloseTo(4 / 3, 5);
    });

    /**
     * A product photo's shape is not the uploader's choice: the card, the
     * gallery and the placeholder are all 4:3, and a square or a banner cut
     * here is letterboxed everywhere it is drawn.
     */
    it('offers no ratio or size choice when the caller fixes the shape', () => {
        open({ aspectRatio: 4 / 3, lockAspect: true });

        expect(screen.queryByText('1:1 Square')).toBeNull();
        expect(screen.queryByText('16:9 Banner')).toBeNull();
        expect(screen.queryByText('Freeform')).toBeNull();
        expect(screen.queryByText(/Output Dimensions/)).toBeNull();
    });

    it('still offers them when it does not', () => {
        open({ aspectRatio: 1 });

        expect(screen.getByText('1:1 Square')).toBeTruthy();
        expect(screen.getByText(/Output Dimensions/)).toBeTruthy();
    });
    describe('a batch of photos', () => {
        const three = () => [
            new File(['a'], 'a.png', { type: 'image/png' }),
            new File(['b'], 'b.png', { type: 'image/png' }),
            new File(['c'], 'c.png', { type: 'image/png' }),
        ];

        const pickMany = (files) => {
            const input = document.querySelector('input[type="file"]');
            fireEvent.change(input, { target: { files } });
        };

        const ready = () =>
            waitFor(() => {
                expect(
                    screen.getByRole('button', { name: /crop/i }),
                ).not.toBeDisabled();
            });

        it('reports which photo of the batch it is on', async () => {
            open({ multiple: true });
            pickMany(three());
            await ready();

            expect(screen.getByText('Photo 1 of 3')).toBeTruthy();
        });

        /*
         * The whole point. A caller that closed on every completion — which is
         * what the product form did — ended the errand after the first photo
         * of six.
         */
        it('stays open and moves on after each crop', async () => {
            const onClose = vi.fn();
            const onCropComplete = vi.fn();
            open({ multiple: true, onClose, onCropComplete });
            pickMany(three());
            await ready();

            fireEvent.click(
                screen.getByRole('button', { name: /crop & next/i }),
            );

            await waitFor(() =>
                expect(screen.getByText('Photo 2 of 3')).toBeTruthy(),
            );
            expect(onCropComplete).toHaveBeenCalledTimes(1);
            expect(onClose).not.toHaveBeenCalled();
        });

        /* Picking six and finding the fourth wrong should cost that one. */
        it('skips one without uploading it or ending the batch', async () => {
            const onClose = vi.fn();
            const onCropComplete = vi.fn();
            open({ multiple: true, onClose, onCropComplete });
            pickMany(three());
            await ready();

            fireEvent.click(
                screen.getByRole('button', { name: /skip this one/i }),
            );

            await waitFor(() =>
                expect(screen.getByText('Photo 2 of 3')).toBeTruthy(),
            );
            expect(onCropComplete).not.toHaveBeenCalled();
            expect(onClose).not.toHaveBeenCalled();
        });

        it('closes once the last one is dealt with', async () => {
            const onClose = vi.fn();
            open({
                multiple: true,
                onClose,
                onCropComplete: () => {},
            });
            pickMany([three()[0]]);
            await ready();

            fireEvent.click(
                screen.getByRole('button', { name: /apply & crop/i }),
            );

            await waitFor(() => expect(onClose).toHaveBeenCalled());
        });

        /* No batch, no progress line and nothing to skip. */
        it('says nothing about a batch for a single pick', async () => {
            open();
            pick(new File(['x'], 'one.png', { type: 'image/png' }));
            await ready();

            expect(screen.queryByText(/Photo 1 of/)).toBeNull();
            expect(screen.queryByRole('button', { name: /skip/i })).toBeNull();
        });
    });
});
