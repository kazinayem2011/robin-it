import React from 'react';
import {
    Star,
    Trash2,
    ChevronLeft,
    ChevronRight,
    Plus,
    Crop,
} from 'lucide-react';
import Button from './Button';
import './ImageGalleryEditor.css';

/**
 * Several photos for one thing — a product, or one of its options.
 *
 * The form used to carry a single `image_path` string, so a product had
 * exactly one photo: no back of the box, no ports, no what-is-in-the-carton.
 * The `product_images` table could always hold more; nothing ever wrote a
 * second row.
 *
 * The first photo leads — it is what the catalogue card, the cart line and the
 * search result show — so it is marked rather than merely first by accident,
 * and promoting one is a single click.
 *
 * Files come in through the cropper (a mixed-size gallery looks broken on a
 * product page), and `onPick` hands control back to whoever owns it: this
 * component never uploads, so the same cropper serves the product and every
 * option without one instance per row.
 */
export default function ImageGalleryEditor({
    images = [],
    onChange,
    onPick,
    label = 'Photos',
    helperText = '',
    max = 12,
    busy = false,
    compact = false,
    emptyHint = 'No photos yet.',
}) {
    const full = images.length >= max;

    const replace = (next) =>
        onChange(next.map((img, i) => ({ ...img, is_primary: i === 0 })));

    const move = (index, by) => {
        const next = [...images];
        const target = index + by;

        if (target < 0 || target >= next.length) return;

        [next[index], next[target]] = [next[target], next[index]];
        replace(next);
    };

    const remove = (index) => replace(images.filter((_, i) => i !== index));

    /*
     * Promotion lifts a photo to the front and leaves the rest in order.
     *
     * Not a swap with the first — that is what this did, and it sent the photo
     * that used to lead all the way to the position of the one being promoted,
     * so making the fifth shot the main one buried the old main at fifth.
     *
     * A move to the front rather than a flag flip: the lead shot is the first
     * one, and two sources of truth for "which is first" drift apart.
     */
    const promote = (index) => {
        if (index <= 0) return;

        const next = [...images];
        next.unshift(next.splice(index, 1)[0]);
        replace(next);
    };

    const setAlt = (index, value) => {
        const next = [...images];
        next[index] = { ...next[index], alt_text: value };
        replace(next);
    };

    return (
        <div className={`gallery-editor ${compact ? 'is-compact' : ''}`}>
            <div className="gallery-editor-head">
                <label className="auth-label">
                    {label}
                    <span className="gallery-editor-count">
                        {images.length}/{max}
                    </span>
                </label>

                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    icon={images.length ? Plus : Crop}
                    loading={busy}
                    disabled={busy || full}
                    onClick={() => onPick?.()}
                >
                    {full
                        ? `Limit ${max}`
                        : images.length
                          ? 'Add photo'
                          : 'Add photo'}
                </Button>
            </div>

            {images.length === 0 ? (
                <p className="gallery-editor-empty">{emptyHint}</p>
            ) : (
                <ul className="gallery-editor-grid">
                    {images.map((img, index) => (
                        <li
                            key={img.id ?? img.image_path ?? index}
                            className={`gallery-editor-item ${index === 0 ? 'is-primary' : ''}`}
                        >
                            <div className="gallery-editor-thumb">
                                <img
                                    src={img.image_path}
                                    alt={img.alt_text || `Photo ${index + 1}`}
                                />
                                {index === 0 && (
                                    <span className="gallery-editor-badge">
                                        <Star size={10} /> Main
                                    </span>
                                )}
                            </div>

                            <div className="gallery-editor-tools">
                                <button
                                    type="button"
                                    title="Move left"
                                    aria-label={`Move photo ${index + 1} earlier`}
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    <ChevronLeft size={13} />
                                </button>
                                <button
                                    type="button"
                                    title="Make this the main photo"
                                    aria-label={`Make photo ${index + 1} the main photo`}
                                    disabled={index === 0}
                                    onClick={() => promote(index)}
                                >
                                    <Star size={13} />
                                </button>
                                <button
                                    type="button"
                                    title="Move right"
                                    aria-label={`Move photo ${index + 1} later`}
                                    disabled={index === images.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    <ChevronRight size={13} />
                                </button>
                                <button
                                    type="button"
                                    title="Remove"
                                    aria-label={`Remove photo ${index + 1}`}
                                    className="is-danger"
                                    onClick={() => remove(index)}
                                >
                                    <Trash2 size={13} />
                                </button>
                            </div>

                            {!compact && (
                                <input
                                    type="text"
                                    className="gallery-editor-alt"
                                    placeholder="Describe it (for search and screen readers)"
                                    value={img.alt_text ?? ''}
                                    onChange={(e) =>
                                        setAlt(index, e.target.value)
                                    }
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {helperText && (
                <span className="auth-field-hint">{helperText}</span>
            )}
        </div>
    );
}
