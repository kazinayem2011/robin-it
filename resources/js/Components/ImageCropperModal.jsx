import React, { useState, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import {
    Crop,
    RotateCw,
    ZoomIn,
    ZoomOut,
    Check,
    X,
    RefreshCw,
    Upload,
} from 'lucide-react';
import Button from './Button';

/**
 * Reusable Image Cropper Modal (SSOT & Pure React Canvas)
 * Allows custom height, width, aspect ratios, zoom, and rotation.
 *
 * Props:
 * - isOpen: boolean
 * - onClose: () => void
 * - onCropComplete: ({ dataUrl, blob, file }) => void
 * - imageSrc: string | File (initial image source or file)
 * - targetWidth: number (output width in px, default 800)
 * - targetHeight: number (output height in px, default 800)
 * - aspectRatio: number | null (e.g. 1, 16/9, 4/3, null for free)
 * - title: string (modal title)
 */
/*
 * Module scope, not a default parameter.
 *
 * As an inline default this array was rebuilt on every render, which gave
 * validateAndProcessFile a new identity every render, which re-ran the effect
 * that loads the image on every render. See that effect for what it then did.
 */
const DEFAULT_ACCEPTED_TYPES = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/avif',
    'image/jpg',
];

export const ImageCropperModal = ({
    isOpen,
    onClose,
    onCropComplete,
    imageSrc = null,
    targetWidth = 800,
    targetHeight = 800,
    aspectRatio = 1,
    /*
     * Hold the crop to `aspectRatio` and hide the ratio and size controls.
     *
     * For a product photo the shape is not the uploader's choice — the card,
     * the gallery and the placeholder are all 4:3, and a square or a banner
     * cut here is letterboxed everywhere it is drawn.
     */
    lockAspect = false,
    /*
     * Let one visit to the file picker bring several photos.
     *
     * A gallery is normally filled in one go, and reopening the cropper per
     * photo made adding six a six-times repeated errand. They queue instead:
     * the first is loaded, and cropping or skipping it moves to the next.
     */
    multiple = false,
    title = 'Crop & Optimize Image',
    maxSizeMB = 10, // Maximum allowed file upload size in Megabytes
    acceptedTypes = DEFAULT_ACCEPTED_TYPES,
    outputFormat = 'image/jpeg', // 'image/jpeg' | 'image/webp' | 'image/png'
    outputQuality = 0.92, // 0.1 to 1.0
}) => {
    const [imageObj, setImageObj] = useState(null);
    const [fileError, setFileError] = useState(null);
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0); // 0, 90, 180, 270
    const [offset, setOffset] = useState({ x: 0, y: 0 });
    const [isDragging, setIsDragging] = useState(false);
    const [dragStart, setDragStart] = useState({ x: 0, y: 0 });
    const [selectedAspect, setSelectedAspect] = useState(aspectRatio);
    /*
     * The photos still to be dealt with, and which one is on screen. Empty for
     * a single pick, which is every caller that does not ask for `multiple`.
     */
    const [queue, setQueue] = useState([]);
    const [queueIndex, setQueueIndex] = useState(0);

    const [customWidth, setCustomWidth] = useState(targetWidth);
    const [customHeight, setCustomHeight] = useState(targetHeight);

    /*
     * The size the export will actually write, and the size the button claims
     * it will. They were separate: the label read customWidth × customHeight
     * while the export squashed the crop into that box, so for a locked 4:3 it
     * announced 800×800 and produced a stretched square. One value now, so the
     * label cannot describe a file the export does not make.
     */
    const outputWidth = parseInt(customWidth, 10) || 800;
    const outputHeight = selectedAspect
        ? Math.round(outputWidth / selectedAspect)
        : parseInt(customHeight, 10) || 800;

    const canvasRef = useRef(null);

    // Validate and Load File
    const validateAndProcessFile = useCallback(
        (file) => {
            setFileError(null);

            // 1. Type validation
            if (!acceptedTypes.includes(file.type)) {
                const errorMsg = `Unsupported file type (${file.type || 'unknown'}). Supported formats: JPG, PNG, WebP, AVIF.`;
                setFileError(errorMsg);
                return false;
            }

            // 2. Size validation
            const maxBytes = maxSizeMB * 1024 * 1024;
            if (file.size > maxBytes) {
                const actualMB = (file.size / (1024 * 1024)).toFixed(1);
                const errorMsg = `File size (${actualMB} MB) exceeds the maximum limit of ${maxSizeMB} MB.`;
                setFileError(errorMsg);
                return false;
            }

            return true;
            // Rebuilt only when the limits it enforces change, so the effect below
            // can name it as a dependency without re-running on every render.
        },
        [acceptedTypes, maxSizeMB],
    );

    /*
     * Load whatever the caller handed in.
     *
     * The early return used to be `setImageObj(null)`, and that made the
     * cropper impossible to upload through. Every caller in the admin opens
     * this modal empty and lets the user browse for a file from inside it, so
     * `imageSrc` is null the whole time. Choosing a file set imageObj, the
     * re-render re-ran this effect — its dependencies changed identity on
     * every render — and the first branch immediately set it back to null. The
     * canvas never appeared and "Apply & Crop" stayed disabled for good.
     *
     * Nothing is cleared here now; the modal empties itself when it closes.
     */
    useEffect(() => {
        if (!imageSrc) return;

        if (imageSrc instanceof File || imageSrc instanceof Blob) {
            if (!validateAndProcessFile(imageSrc)) return;
        }

        const img = new Image();
        img.crossOrigin = 'anonymous';

        if (typeof imageSrc === 'string') {
            img.src = imageSrc;
        } else if (imageSrc instanceof File || imageSrc instanceof Blob) {
            img.src = URL.createObjectURL(imageSrc);
        }

        img.onload = () => {
            setImageObj(img);
            setZoom(1);
            setRotation(0);
            setOffset({ x: 0, y: 0 });
            setFileError(null);
        };
    }, [imageSrc, validateAndProcessFile]);

    // Emptied on close, so the next thing cropped does not open onto the last.
    useEffect(() => {
        if (!isOpen) {
            setImageObj(null);
            setFileError(null);
        }
    }, [isOpen]);

    /** Put one file on the canvas, resetting whatever was done to the last. */
    const loadFile = useCallback(
        (file) => {
            if (!file || !validateAndProcessFile(file)) return;

            const reader = new FileReader();
            reader.onload = (event) => {
                const img = new Image();
                img.src = event.target.result;
                img.onload = () => {
                    setImageObj(img);
                    setZoom(1);
                    setRotation(0);
                    setOffset({ x: 0, y: 0 });
                    setFileError(null);
                };
            };
            reader.readAsDataURL(file);
        },
        [validateAndProcessFile],
    );

    // Handle Local File Upload from modal
    const handleFileChange = (e) => {
        const files = Array.from(e.target.files || []);
        if (!files.length) return;

        setQueue(multiple ? files : []);
        setQueueIndex(0);
        loadFile(files[0]);
    };

    /*
     * Move to the next photo in the queue, or leave when there is none.
     *
     * Both finishing one and skipping one come through here, so the two cannot
     * disagree about when the modal is done.
     */
    const advance = () => {
        const next = queueIndex + 1;

        if (next < queue.length) {
            setQueueIndex(next);
            setImageObj(null);
            loadFile(queue[next]);

            return;
        }

        setQueue([]);
        setQueueIndex(0);
        onClose?.();
    };

    // Draw Main Interactive Canvas
    const drawCanvas = useCallback(() => {
        const canvas = canvasRef.current;
        if (!canvas || !imageObj) return;

        const ctx = canvas.getContext('2d');
        const cw = canvas.width;
        const ch = canvas.height;

        ctx.clearRect(0, 0, cw, ch);

        // Background checkerboard
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(0, 0, cw, ch);

        ctx.save();
        ctx.translate(cw / 2 + offset.x, ch / 2 + offset.y);
        ctx.rotate((rotation * Math.PI) / 180);
        ctx.scale(zoom, zoom);

        // Calculate aspect-fit base size
        const scale = Math.min(
            (cw * 0.85) / imageObj.width,
            (ch * 0.85) / imageObj.height,
        );
        const dw = imageObj.width * scale;
        const dh = imageObj.height * scale;

        ctx.drawImage(imageObj, -dw / 2, -dh / 2, dw, dh);
        ctx.restore();

        // Draw Crop Overlay Box
        ctx.save();
        ctx.fillStyle = 'rgba(15, 23, 42, 0.6)';

        let boxW = cw * 0.8;
        let boxH = ch * 0.8;

        if (selectedAspect) {
            if (selectedAspect >= 1) {
                boxW = cw * 0.75;
                boxH = boxW / selectedAspect;
                if (boxH > ch * 0.75) {
                    boxH = ch * 0.75;
                    boxW = boxH * selectedAspect;
                }
            } else {
                boxH = ch * 0.75;
                boxW = boxH * selectedAspect;
                if (boxW > cw * 0.75) {
                    boxW = cw * 0.75;
                    boxH = boxW / selectedAspect;
                }
            }
        }

        const boxX = (cw - boxW) / 2;
        const boxY = (ch - boxH) / 2;

        // Darken outside crop zone
        ctx.beginPath();
        ctx.rect(0, 0, cw, ch);
        ctx.rect(boxX + boxW, boxY, -boxW, boxH); // Counter-clockwise cutout
        ctx.fill();

        // Crop zone border & grid
        ctx.strokeStyle = '#ea484f';
        ctx.lineWidth = 2;
        ctx.strokeRect(boxX, boxY, boxW, boxH);

        // Rule of thirds grid lines
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.3)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        // Verticals
        ctx.moveTo(boxX + boxW / 3, boxY);
        ctx.lineTo(boxX + boxW / 3, boxY + boxH);
        ctx.moveTo(boxX + (boxW * 2) / 3, boxY);
        ctx.lineTo(boxX + (boxW * 2) / 3, boxY + boxH);
        // Horizontals
        ctx.moveTo(boxX, boxY + boxH / 3);
        ctx.lineTo(boxX + boxW, boxY + boxH / 3);
        ctx.moveTo(boxX, boxY + (boxH * 2) / 3);
        ctx.lineTo(boxX + boxW, boxY + (boxH * 2) / 3);
        ctx.stroke();

        ctx.restore();
    }, [imageObj, zoom, rotation, offset, selectedAspect]);

    useEffect(() => {
        drawCanvas();
    }, [drawCanvas]);

    // Mouse / Touch Drag Handlers
    const handleMouseDown = (e) => {
        setIsDragging(true);
        setDragStart({ x: e.clientX - offset.x, y: e.clientY - offset.y });
    };

    const handleMouseMove = (e) => {
        if (!isDragging) return;
        setOffset({
            x: e.clientX - dragStart.x,
            y: e.clientY - dragStart.y,
        });
    };

    const handleMouseUp = () => setIsDragging(false);

    // Export Cropped Image to target resolution
    const handleSaveCrop = () => {
        if (!imageObj) return;

        const outCanvas = document.createElement('canvas');

        /*
         * The output has to be the shape of the selection, or the export
         * stretches it.
         *
         * The width and height were read straight from their inputs, which
         * start at targetWidth/targetHeight and know nothing about the ratio:
         * a caller asking for a 4:3 crop with the default 800x800 output got
         * its selection squashed into a square by the non-uniform scale below.
         * Every product photo cropped since 4:3 was introduced came out
         * stretched for exactly that reason.
         *
         * Width is what the caller asked for; height follows the ratio. Only
         * a freeform crop, which has no ratio to follow, uses both inputs.
         */
        outCanvas.width = outputWidth;
        outCanvas.height = outputHeight;

        const outCtx = outCanvas.getContext('2d');

        const cw = 500;
        const ch = 400;

        let boxW = cw * 0.8;
        let boxH = ch * 0.8;

        if (selectedAspect) {
            if (selectedAspect >= 1) {
                boxW = cw * 0.75;
                boxH = boxW / selectedAspect;
                if (boxH > ch * 0.75) {
                    boxH = ch * 0.75;
                    boxW = boxH * selectedAspect;
                }
            } else {
                boxH = ch * 0.75;
                boxW = boxH * selectedAspect;
                if (boxW > cw * 0.75) {
                    boxW = cw * 0.75;
                    boxH = boxW / selectedAspect;
                }
            }
        }

        const boxX = (cw - boxW) / 2;
        const boxY = (ch - boxH) / 2;

        outCtx.fillStyle = '#ffffff';
        outCtx.fillRect(0, 0, outCanvas.width, outCanvas.height);

        outCtx.save();
        outCtx.translate(outCanvas.width / 2, outCanvas.height / 2);
        outCtx.scale(outCanvas.width / boxW, outCanvas.height / boxH);
        outCtx.translate(
            -(boxX + boxW / 2) + (cw / 2 + offset.x),
            -(boxY + boxH / 2) + (ch / 2 + offset.y),
        );
        outCtx.rotate((rotation * Math.PI) / 180);
        outCtx.scale(zoom, zoom);

        const scale = Math.min(
            (cw * 0.85) / imageObj.width,
            (ch * 0.85) / imageObj.height,
        );
        const dw = imageObj.width * scale;
        const dh = imageObj.height * scale;

        outCtx.drawImage(imageObj, -dw / 2, -dh / 2, dw, dh);
        outCtx.restore();

        /*
         * outputFormat and outputQuality are documented props that were never
         * read — the export hardcoded JPEG at 0.92, so asking the cropper for a
         * WebP or a PNG silently produced a JPEG with the wrong file extension.
         */
        const dataUrl = outCanvas.toDataURL(outputFormat, outputQuality);
        const extension =
            outputFormat.split('/')[1]?.replace('jpeg', 'jpg') ?? 'jpg';

        outCanvas.toBlob(
            (blob) => {
                const file = new File([blob], `cropped_image.${extension}`, {
                    type: outputFormat,
                });
                if (onCropComplete) {
                    onCropComplete({
                        dataUrl,
                        blob,
                        file,
                        width: outCanvas.width,
                        height: outCanvas.height,
                    });
                }

                /*
                 * The modal decides when it is finished, not the caller. With
                 * several photos queued there is another one to crop, and a
                 * caller that closed on every completion would end the errand
                 * after the first.
                 */
                advance();
            },
            outputFormat,
            outputQuality,
        );
    };

    if (!isOpen) return null;

    /*
     * Rendered into <body> rather than where it is called from.
     *
     * A modal placed inside a parent that creates a stacking context — anything
     * positioned with a z-index, a transform, or position: sticky — has its own
     * z-index scoped to that parent, however large the number. The avatar
     * uploader sits in the account sidebar, which is sticky, and that was
     * enough to paint this overlay underneath the site header: the crop
     * dialog's title and close button vanished behind the search bar.
     */
    return createPortal(
        <div className="cropper-modal-overlay" onClick={onClose}>
            <div
                className="cropper-modal-dialog"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Modal Header */}
                <div className="cropper-modal-header">
                    <div className="cropper-title-group">
                        <Crop size={18} className="cropper-icon-accent" />
                        <h3>{title}</h3>
                    </div>
                    <button
                        type="button"
                        className="cropper-close-btn"
                        onClick={onClose}
                    >
                        <X size={20} />
                    </button>
                </div>

                {/* Modal Body */}
                <div className="cropper-modal-body">
                    {/* Left: Interactive Canvas Work Area */}
                    <div className="cropper-canvas-wrapper">
                        {imageObj ? (
                            <canvas
                                ref={canvasRef}
                                width={500}
                                height={400}
                                className="cropper-interactive-canvas"
                                onMouseDown={handleMouseDown}
                                onMouseMove={handleMouseMove}
                                onMouseUp={handleMouseUp}
                                onMouseLeave={handleMouseUp}
                            />
                        ) : (
                            <div className="cropper-upload-empty">
                                <Upload
                                    size={40}
                                    className="empty-upload-icon"
                                />
                                <p>
                                    Select an image to start cropping &amp;
                                    resizing
                                </p>
                                <span className="cropper-upload-limits-text">
                                    Supported: JPG, PNG, WebP, AVIF (Max{' '}
                                    {maxSizeMB} MB)
                                </span>
                                {fileError && (
                                    <div className="cropper-error-banner">
                                        ⚠️ {fileError}
                                    </div>
                                )}
                                <label className="cropper-file-upload-btn">
                                    {multiple
                                        ? 'Browse Image Files'
                                        : 'Browse Image File'}
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,image/avif,image/jpg"
                                        multiple={multiple}
                                        onChange={handleFileChange}
                                        style={{ display: 'none' }}
                                    />
                                </label>
                            </div>
                        )}

                        {imageObj && (
                            <div className="cropper-canvas-tip">
                                💡 Click &amp; drag to reposition image inside
                                the red crop frame.
                            </div>
                        )}
                    </div>

                    {/* Right: Controls & Presets Sidebar */}
                    <div className="cropper-sidebar-controls">
                        {/* Aspect Ratio Presets. Absent when the caller
                            fixes the shape: offering a choice that is then
                            overridden is worse than not offering one. */}
                        {!lockAspect && (
                            <div className="control-group">
                                <label className="control-label">
                                    Aspect Ratio
                                </label>
                                <div className="aspect-ratio-btn-grid">
                                    <button
                                        type="button"
                                        className={`aspect-btn ${selectedAspect === 1 ? 'active' : ''}`}
                                        onClick={() => {
                                            setSelectedAspect(1);
                                            setCustomWidth(800);
                                            setCustomHeight(800);
                                        }}
                                    >
                                        1:1 Square
                                    </button>
                                    <button
                                        type="button"
                                        className={`aspect-btn ${selectedAspect === 16 / 9 ? 'active' : ''}`}
                                        onClick={() => {
                                            setSelectedAspect(16 / 9);
                                            setCustomWidth(1280);
                                            setCustomHeight(720);
                                        }}
                                    >
                                        16:9 Banner
                                    </button>
                                    <button
                                        type="button"
                                        className={`aspect-btn ${selectedAspect === 4 / 3 ? 'active' : ''}`}
                                        onClick={() => {
                                            setSelectedAspect(4 / 3);
                                            setCustomWidth(800);
                                            setCustomHeight(600);
                                        }}
                                    >
                                        4:3 Standard
                                    </button>
                                    <button
                                        type="button"
                                        className={`aspect-btn ${selectedAspect === null ? 'active' : ''}`}
                                        onClick={() => setSelectedAspect(null)}
                                    >
                                        Freeform
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* Custom Output Resolution. Hidden with the ratio:
                            the height is derived from it, so a height field
                            here would be a number the export ignores. */}
                        {!lockAspect && (
                            <div className="control-group">
                                <label className="control-label">
                                    Output Dimensions (px)
                                </label>
                                <div className="dimensions-input-row">
                                    <div className="dim-field">
                                        <span>W</span>
                                        <input
                                            type="number"
                                            value={customWidth}
                                            onChange={(e) =>
                                                setCustomWidth(e.target.value)
                                            }
                                            min={50}
                                            max={3840}
                                        />
                                    </div>
                                    <span className="dim-separator">×</span>
                                    <div className="dim-field">
                                        <span>H</span>
                                        <input
                                            type="number"
                                            value={customHeight}
                                            onChange={(e) =>
                                                setCustomHeight(e.target.value)
                                            }
                                            min={50}
                                            max={3840}
                                        />
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Zoom Slider */}
                        <div className="control-group">
                            <div className="control-label-row">
                                <label className="control-label">
                                    Zoom Scale
                                </label>
                                <span className="zoom-value">
                                    {Math.round(zoom * 100)}%
                                </span>
                            </div>
                            <div className="zoom-slider-row">
                                <ZoomOut size={16} />
                                <input
                                    type="range"
                                    min="0.5"
                                    max="3"
                                    step="0.05"
                                    value={zoom}
                                    onChange={(e) =>
                                        setZoom(parseFloat(e.target.value))
                                    }
                                    className="zoom-slider"
                                />
                                <ZoomIn size={16} />
                            </div>
                        </div>

                        {/* Rotation & Quick Tools */}
                        <div className="control-group">
                            <label className="control-label">
                                Transform Tools
                            </label>
                            <div className="transform-tools-row">
                                <button
                                    type="button"
                                    className="tool-action-btn"
                                    onClick={() =>
                                        setRotation((prev) => (prev + 90) % 360)
                                    }
                                    title="Rotate 90°"
                                >
                                    <RotateCw size={15} /> Rotate 90°
                                </button>
                                <button
                                    type="button"
                                    className="tool-action-btn"
                                    onClick={() => {
                                        setZoom(1);
                                        setRotation(0);
                                        setOffset({ x: 0, y: 0 });
                                    }}
                                    title="Reset Position"
                                >
                                    <RefreshCw size={15} /> Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Modal Footer Actions */}
                <div className="cropper-modal-footer">
                    {/* Which of the queued photos this is. Absent for a single
                        pick, where there is no progress to report. */}
                    {queue.length > 1 && (
                        <span className="cropper-queue-count">
                            Photo {queueIndex + 1} of {queue.length}
                        </span>
                    )}

                    <Button variant="ghost" onClick={onClose}>
                        {queue.length > 1 ? 'Cancel the rest' : 'Cancel'}
                    </Button>

                    {/*
                        Skipping one is not cancelling the errand. Picking six
                        photos and finding the fourth is the wrong shot should
                        cost that one photo, not the two behind it.
                    */}
                    {queue.length > 1 && queueIndex < queue.length - 1 && (
                        <Button variant="secondary" onClick={advance}>
                            Skip this one
                        </Button>
                    )}

                    <Button
                        variant="primary"
                        icon={Check}
                        disabled={!imageObj}
                        onClick={handleSaveCrop}
                    >
                        {queue.length > 1 && queueIndex < queue.length - 1
                            ? 'Crop & next'
                            : `Apply & Crop (${outputWidth}×${outputHeight}px)`}
                    </Button>
                </div>
            </div>
        </div>,
        document.body,
    );
};

export default ImageCropperModal;
