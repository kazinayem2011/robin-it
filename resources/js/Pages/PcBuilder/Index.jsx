import React, { useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import {
    Button,
    Spinner,
    toast,
    Modal,
    PcBuilderQuotationModal,
} from '../../Components';
import { pcBuilderService, cartService } from '../../services';
import useAppStore from '../../store/useAppStore';
import { formatBdt } from '../../utils/formatters';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import {
    Cpu,
    Server,
    Layers,
    HardDrive,
    Monitor,
    Zap,
    Box,
    Wind,
    Tv,
    Plus,
    X,
    ShoppingCart,
    Printer,
    RotateCcw,
    Sparkles,
    Share2,
    Copy,
    Check,
    FileText,
    AlertTriangle,
    CheckCircle2,
} from 'lucide-react';
import './PcBuilder.css';

const ICON_MAP = {
    Cpu,
    Server,
    Layers,
    HardDrive,
    Monitor,
    Zap,
    Box,
    Wind,
    Tv,
};

export default function PcBuilderIndex() {
    const [categories, setCategories] = useState([]);
    const [loading, setLoading] = useState(true);
    const [addingToCart, setAddingToCart] = useState(false);
    const [savingBuild, setSavingBuild] = useState(false);
    const [shareModalOpen, setShareModalOpen] = useState(false);
    const [shareUrl, setShareUrl] = useState('');
    const [copied, setCopied] = useState(false);
    const [quotationOpen, setQuotationOpen] = useState(false);

    const pcBuilderItems = useAppStore((state) => state.pcBuilderItems);
    const setPcBuilderItem = useAppStore((state) => state.setPcBuilderItem);
    const removePcBuilderItem = useAppStore(
        (state) => state.removePcBuilderItem,
    );
    const clearPcBuilder = useAppStore((state) => state.clearPcBuilder);
    const [compat, setCompat] = useState(null);
    const [checkingCompat, setCheckingCompat] = useState(false);

    useEffect(() => {
        const fetchCategories = async () => {
            setLoading(true);
            try {
                const data = await pcBuilderService.getCategories();
                if (data && Array.isArray(data)) {
                    setCategories(data);
                }

                // Check if share code is present in URL
                const params = new URLSearchParams(window.location.search);
                const shareCode = params.get('share');
                if (shareCode) {
                    try {
                        // The axios interceptor already unwraps to the envelope,
                        // so the payload is `res.data` — not `res.data.data`.
                        const build =
                            await pcBuilderService.loadBuild(shareCode);

                        (build?.components || []).forEach((comp) => {
                            if (comp.product) {
                                setPcBuilderItem(
                                    comp.componentId,
                                    comp.product,
                                );
                            }
                        });

                        toast.success(
                            `Loaded saved PC Build: "${build.build_name}"`,
                        );

                        if (build?.unavailable_count > 0) {
                            toast.warning(
                                `${build.unavailable_count} component(s) in this build are no longer available and were skipped.`,
                                'Build Partially Loaded',
                            );
                        }
                    } catch (e) {
                        toast.error(
                            e?.message ||
                                'Could not load shared PC configuration.',
                        );
                    }
                }
            } catch (error) {
                console.error('Failed to load PC builder categories', error);
            } finally {
                setLoading(false);
            }
        };
        fetchCategories();
    }, []);

    // Ask the server to validate the build whenever the selection changes.
    useEffect(() => {
        if (pcBuilderItems.length === 0) {
            setCompat(null);
            return;
        }

        const selection = pcBuilderItems.reduce((acc, item) => {
            acc[item.componentId] = item.product.id;
            return acc;
        }, {});

        let cancelled = false;
        setCheckingCompat(true);

        pcBuilderService
            .checkCompatibility(selection)
            .then((data) => {
                if (!cancelled) setCompat(data);
            })
            .catch((err) => {
                console.error('Compatibility check failed', err);
                if (!cancelled) setCompat(null);
            })
            .finally(() => {
                if (!cancelled) setCheckingCompat(false);
            });

        return () => {
            cancelled = true;
        };
    }, [pcBuilderItems]);

    // Calculate Estimated Wattage & Total Cost
    const totalCost = pcBuilderItems.reduce((sum, item) => {
        const price = Number(
            item.product.raw_price ?? item.product.effective_price ?? 0,
        );
        return sum + (Number.isFinite(price) ? price : 0);
    }, 0);

    // The server computes this from real TDP specs; fall back to the per-card
    // wattage only while the check is in flight.
    const estimatedWattage =
        compat?.power?.estimated ??
        pcBuilderItems.reduce((sum, item) => {
            const watts = Number(item.product.wattage);
            return sum + (Number.isFinite(watts) && watts > 0 ? watts : 50);
        }, 100);

    const handleAddAllToCart = async () => {
        if (pcBuilderItems.length === 0) {
            toast.warning('Please choose components before adding to cart.');
            return;
        }

        // Don't let someone buy parts we know do not work together.
        if (compat?.status === 'fail') {
            toast.error(compat.issues[0].message, 'Incompatible Build');
            return;
        }

        setAddingToCart(true);
        const failures = [];

        try {
            // Added one at a time so a single out-of-stock part doesn't silently
            // abandon the rest of the rig.
            for (const item of pcBuilderItems) {
                try {
                    await cartService.addToCart(item.product.id, 1);
                } catch (error) {
                    failures.push(
                        error?.message || `Could not add ${item.product.name}.`,
                    );
                }
            }

            useAppStore.getState().fetchCartCount();

            const added = pcBuilderItems.length - failures.length;

            if (added > 0) {
                toast.success(
                    `Added ${added} of ${pcBuilderItems.length} components to your cart.`,
                    'Rig Added',
                );
            }

            failures.forEach((message) => toast.warning(message, 'Not Added'));

            if (added > 0) {
                router.visit(ROUTES.CART);
            }
        } finally {
            setAddingToCart(false);
        }
    };

    const handleSaveAndShare = async () => {
        if (pcBuilderItems.length === 0) {
            toast.warning('Select components before saving your build.');
            return;
        }

        setSavingBuild(true);
        try {
            const build = await pcBuilderService.saveBuild({
                components: pcBuilderItems.map((item) => ({
                    componentId: item.componentId,
                    product_id: item.product.id,
                    quantity: 1,
                })),
                build_name: 'Custom Rig',
            });

            if (build?.share_url) {
                setShareUrl(build.share_url);
                setShareModalOpen(true);
            }
        } catch (err) {
            toast.error(
                err?.message || 'Failed to save PC Build configuration.',
                'Save Failed',
            );
        } finally {
            setSavingBuild(false);
        }
    };

    const handleCopyLink = () => {
        navigator.clipboard.writeText(shareUrl);
        setCopied(true);
        toast.success('Share link copied to clipboard!');
        setTimeout(() => setCopied(false), 3000);
    };

    const handlePrint = () => {
        window.print();
    };

    return (
        <MainLayout>
            <Head title={`Custom PC Builder — ${siteConfig.name}`} />

            <div className="pc-builder-wrapper container">
                {/* Header Banner */}
                <div className="pc-builder-header-banner">
                    <div>
                        <div className="pc-builder-tag-row">
                            <span className="badge badge-discount">
                                FLAGSHIP TOOL
                            </span>
                            <span className="pc-builder-tagline">
                                {checkingCompat
                                    ? 'Checking compatibility…'
                                    : compat?.status === 'fail'
                                      ? `${compat.issues.length} compatibility issue${compat.issues.length === 1 ? '' : 's'}`
                                      : compat?.status === 'pass'
                                        ? 'All parts compatible'
                                        : compat?.issues?.length > 0
                                          ? 'Some parts need checking'
                                          : 'Instant Compatibility Check'}
                            </span>
                        </div>
                        <h1 className="pc-builder-main-title">
                            Custom PC Builder
                        </h1>
                        <p className="pc-builder-subtitle">
                            Assemble your dream gaming rig or workstation with
                            genuine authorized parts.
                        </p>
                    </div>

                    <div className="pc-builder-stats">
                        <div className="stat-pill">
                            <span className="stat-pill-label">
                                Estimated Wattage
                            </span>
                            <span className="stat-pill-value">
                                ⚡ {estimatedWattage} W
                            </span>
                        </div>
                        <div className="stat-pill">
                            <span className="stat-pill-label">Total Cost</span>
                            <span className="stat-pill-value price-val">
                                {formatBdt(totalCost)}
                            </span>
                        </div>
                    </div>
                </div>

                {compat && compat.issues.length > 0 && (
                    <div
                        className={`pc-compat-panel ${
                            compat.status === 'fail' ? 'is-error' : 'is-warning'
                        }`}
                        role="alert"
                    >
                        <AlertTriangle size={18} />
                        <div className="pc-compat-panel-body">
                            <strong>
                                {compat.status === 'fail'
                                    ? "These parts won't work together"
                                    : "We couldn't verify every part"}
                            </strong>
                            <ul>
                                {compat.issues.map((issue) => (
                                    <li key={issue.rule}>{issue.message}</li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}

                {compat &&
                    compat.status === 'pass' &&
                    pcBuilderItems.length > 1 && (
                        <div className="pc-compat-panel is-ok" role="status">
                            <CheckCircle2 size={18} />
                            <div className="pc-compat-panel-body">
                                <strong>
                                    All selected parts are compatible
                                </strong>
                                <p>
                                    Estimated draw {compat.power.estimated}W —
                                    we recommend at least{' '}
                                    {compat.power.recommended}W of power supply.
                                </p>
                            </div>
                        </div>
                    )}

                {/* Table of Component Slots */}
                {loading ? (
                    <Spinner
                        text="Loading PC builder blueprint..."
                        fullHeight
                    />
                ) : (
                    <div className="pc-builder-components-table">
                        {categories.map((cat, index) => {
                            const IconComponent = ICON_MAP[cat.icon] || Cpu;
                            const selectedEntry = pcBuilderItems.find(
                                (item) => item.componentId === cat.id,
                            );

                            // The first slot of each group carries its heading.
                            const previousGroup =
                                index > 0 ? categories[index - 1].group : null;
                            const startsGroup = cat.group !== previousGroup;

                            return (
                                <React.Fragment key={cat.id}>
                                    {startsGroup && (
                                        <div className="pc-builder-group-head">
                                            <h3>
                                                {cat.group === 'peripherals'
                                                    ? 'Peripherals & Accessories'
                                                    : 'Core Components'}
                                            </h3>
                                            <span>
                                                {cat.group === 'peripherals'
                                                    ? 'Optional — nothing here has to match the rest'
                                                    : 'These have to fit together; we check as you go'}
                                            </span>
                                        </div>
                                    )}
                                    <div className="pc-builder-row">
                                        {/* Type Column */}
                                        <div className="component-type-col">
                                            <div className="component-icon-box">
                                                <IconComponent size={20} />
                                            </div>
                                            <div className="component-type-info">
                                                <h4>
                                                    {cat.name}
                                                    {cat.required && (
                                                        <span className="required-pill">
                                                            Required
                                                        </span>
                                                    )}
                                                </h4>
                                                {/* A reason this slot matters,
                                                rather than the same "genuine
                                                product with warranty" line
                                                repeated on every row. */}
                                                {cat.hint && <p>{cat.hint}</p>}
                                                {cat.available === 0 && (
                                                    <p className="component-unavailable">
                                                        None in stock right now
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        {/* Component Content Column */}
                                        <div className="component-content-col">
                                            {selectedEntry ? (
                                                <div className="selected-product-box">
                                                    <img
                                                        src={
                                                            selectedEntry
                                                                .product
                                                                .images?.[0]
                                                                ?.image_path ||
                                                            '/images/product-placeholder.svg'
                                                        }
                                                        alt={
                                                            selectedEntry
                                                                .product.name
                                                        }
                                                        className="selected-product-thumb"
                                                    />
                                                    <div className="selected-product-meta">
                                                        <h5 className="selected-product-title">
                                                            {
                                                                selectedEntry
                                                                    .product
                                                                    .name
                                                            }
                                                        </h5>
                                                        <span className="selected-product-badge">
                                                            {selectedEntry
                                                                .product
                                                                .stock_quantity >
                                                            0
                                                                ? 'In Stock'
                                                                : 'Out of Stock'}
                                                        </span>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="empty-component-placeholder">
                                                    No component selected
                                                </div>
                                            )}
                                        </div>

                                        {/* Price Column */}
                                        <div className="component-price-col">
                                            {selectedEntry ? (
                                                <span className="component-live-price">
                                                    {formatBdt(
                                                        selectedEntry.product
                                                            .discount_price ||
                                                            selectedEntry
                                                                .product.price,
                                                    )}
                                                </span>
                                            ) : (
                                                <span className="price-dash">
                                                    —
                                                </span>
                                            )}
                                        </div>

                                        {/* Action Column */}
                                        <div className="component-action-col">
                                            {selectedEntry ? (
                                                <button
                                                    type="button"
                                                    className="btn-remove-component"
                                                    onClick={() =>
                                                        removePcBuilderItem(
                                                            cat.id,
                                                        )
                                                    }
                                                    title="Remove Component"
                                                >
                                                    <X size={16} />
                                                </button>
                                            ) : (
                                                <Link
                                                    href={ROUTES.PC_BUILDER_CHOOSE(
                                                        cat.category_slug,
                                                    )}
                                                    className="btn-choose-component"
                                                >
                                                    <Plus size={16} /> Choose
                                                </Link>
                                            )}
                                        </div>
                                    </div>
                                </React.Fragment>
                            );
                        })}
                    </div>
                )}

                {/* Floating Bottom Action Bar */}
                <div className="pc-builder-floating-bar">
                    <div className="pc-builder-floating-left">
                        <div>
                            <span className="pc-builder-floating-count">
                                Selected ({pcBuilderItems.length} Components)
                            </span>
                            <div className="pc-builder-floating-total">
                                {formatBdt(totalCost)}
                            </div>
                        </div>
                    </div>

                    <div className="pc-builder-floating-right">
                        {pcBuilderItems.length > 0 && (
                            <>
                                <Button
                                    variant="ghost"
                                    size="md"
                                    icon={RotateCcw}
                                    onClick={clearPcBuilder}
                                    className="btn-text-light"
                                >
                                    Clear
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="md"
                                    icon={Share2}
                                    loading={savingBuild}
                                    onClick={handleSaveAndShare}
                                >
                                    Share Rig
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="md"
                                    icon={Printer}
                                    onClick={() => setQuotationOpen(true)}
                                >
                                    Official Quotation
                                </Button>
                            </>
                        )}
                        <Button
                            variant="primary"
                            size="lg"
                            icon={ShoppingCart}
                            loading={addingToCart}
                            disabled={pcBuilderItems.length === 0}
                            onClick={handleAddAllToCart}
                        >
                            Add All to Cart
                        </Button>
                    </div>
                </div>
            </div>

            {/* Official Branded Quotation Print / PDF Modal */}
            <PcBuilderQuotationModal
                isOpen={quotationOpen}
                onClose={() => setQuotationOpen(false)}
                components={pcBuilderItems}
                totalPrice={totalCost}
                estimatedWattage={estimatedWattage}
            />

            {/* Share PC Build Modal */}
            {shareModalOpen && (
                <div
                    className="pc-builder-modal-overlay"
                    onClick={() => setShareModalOpen(false)}
                >
                    <div
                        className="pc-builder-modal-card"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="modal-header-row">
                            <h3>Share Your Custom PC Build</h3>
                            <button
                                type="button"
                                className="modal-close-btn"
                                onClick={() => setShareModalOpen(false)}
                            >
                                <X size={20} />
                            </button>
                        </div>
                        <p className="modal-desc-text">
                            Anyone with this link can view and load your exact
                            hardware configuration:
                        </p>
                        <div className="share-link-input-row">
                            <input
                                type="text"
                                readOnly
                                value={shareUrl}
                                className="share-url-input"
                            />
                            <Button
                                variant="primary"
                                icon={copied ? Check : Copy}
                                onClick={handleCopyLink}
                            >
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </MainLayout>
    );
}
