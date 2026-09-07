import React, { useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import Button from '../../Components/Button';
import Modal from '../../Components/Modal';
import PcBuilderQuotationModal from '../../Components/PcBuilderQuotationModal';
import { BuilderRowsSkeleton } from '../../Components/Skeleton';
import { toast } from '../../Components/Toast';
import { pcBuilderService, cartService } from '../../services';
import useAppStore from '../../store/useAppStore';
import { formatBdt } from '../../utils/formatters';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import { essentialsStatus, stockLabel } from '../../utils/pcBuild';
import { scrollBehavior } from '../../utils/scroll';
import IncompleteBuildModal from './IncompleteBuildModal';
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
    Share2,
    Copy,
    Check,
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
    const [gapPrompt, setGapPrompt] = useState(false);
    const [checkingCompat, setCheckingCompat] = useState(false);

    useEffect(() => {
        const fetchCategories = async () => {
            setLoading(true);
            try {
                const data = await pcBuilderService.getCategories();
                if (data && Array.isArray(data)) {
                    setCategories(data);
                }

                const params = new URLSearchParams(window.location.search);

                /*
                 * Picks carried over from the homepage's three-step widget.
                 *
                 * It links here as ?cpu=&gpu=&ram=, and for a while nothing
                 * read them at all — so choosing a processor, a card and
                 * memory on the front page and pressing "Finalize this rig"
                 * landed you on an empty builder with the work thrown away.
                 *
                 * Reading them was only half of it. The values on the right
                 * are the builder's own component ids, and they were guessed
                 * rather than taken from it: the catalogue calls these
                 * component-processor, component-graphics-card and
                 * component-ram-desktop, so every lookup asked for a slot that
                 * does not exist, found nothing, and carried nothing. It
                 * looked exactly like the original bug, which is presumably
                 * why it survived the fix for it.
                 */
                const carriedOver = {
                    cpu: ['component-processor', 'cpu'],
                    gpu: ['component-graphics-card', 'graphics-card', 'gpu'],
                    ram: ['component-ram-desktop', 'ram', 'memory'],
                };

                /*
                 * Candidates, matched against the slots actually served.
                 *
                 * The server resolves each slot from a list like
                 * ['component-processor', 'cpu'] so a shop on either taxonomy
                 * keeps working — which means the id it sends back is whichever
                 * candidate matched, and naming one of them here would break
                 * again on the other tree. Reading it from `data` is the only
                 * spelling that cannot drift from what the builder is showing.
                 */
                const wanted = Object.entries(carriedOver)
                    .map(([param, candidates]) => {
                        const productId = params.get(param);

                        if (!productId) return null;

                        const slot = (data || []).find((c) =>
                            candidates.includes(c.id),
                        );

                        if (!slot) {
                            console.warn(
                                `PC builder: no slot for "${param}" among ${candidates.join(', ')}.`,
                            );

                            return null;
                        }

                        return [slot.id, productId];
                    })
                    .filter(Boolean);

                if (wanted.length) {
                    await Promise.all(
                        wanted.map(async ([componentId, productId]) => {
                            try {
                                const options =
                                    await pcBuilderService.getComponents(
                                        componentId,
                                    );
                                const match = (options || []).find(
                                    (o) => String(o.id) === String(productId),
                                );
                                if (match) {
                                    setPcBuilderItem(componentId, match);
                                } else {
                                    /*
                                     * A part that has sold out since the front
                                     * page rendered is simply not carried, and
                                     * that is fine. A slot the builder does not
                                     * have is not fine, and silence is what let
                                     * a wrong id sit here unnoticed — every
                                     * carry-over failed and looked like the
                                     * shopper had picked nothing.
                                     */
                                    console.warn(
                                        `PC builder: nothing matched product ${productId} in "${componentId}".`,
                                    );
                                }
                            } catch (error) {
                                console.warn(
                                    `PC builder: could not load "${componentId}" to carry a pick over.`,
                                    error,
                                );
                            }
                        }),
                    );
                }

                // Check if share code is present in URL
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
        // setPcBuilderItem is a zustand action: its identity never changes, so
        // naming it here cannot re-run the fetch.
    }, [setPcBuilderItem]);

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

    // Which of the parts a machine cannot boot without are still empty.
    const essentials = essentialsStatus(categories, pcBuilderItems);

    const handleAddAllToCart = async () => {
        if (pcBuilderItems.length === 0) {
            toast.warning('Please choose components before adding to cart.');
            return;
        }

        /*
         * Incompatible and incomplete are refused differently on purpose.
         *
         * Two parts that provably cannot connect is never something the
         * customer meant, so that stays a hard stop. Missing essentials often
         * is what they meant — upgrades reuse the case and the supply — so it
         * asks instead of refusing.
         */
        if (compat?.status === 'fail') {
            toast.error(compat.issues[0].message, 'Incompatible Build');
            return;
        }

        if (essentials.missing.length > 0) {
            setGapPrompt(true);
            return;
        }

        await addItemsToCart();
    };

    const addItemsToCart = async () => {
        setGapPrompt(false);
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

    return (
        <>
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
                        <div
                            className={`stat-pill ${
                                essentials.complete ? 'is-complete' : ''
                            }`}
                        >
                            <span className="stat-pill-label">Essentials</span>
                            <span className="stat-pill-value">
                                {essentials.chosen} / {essentials.total}
                            </span>
                        </div>
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
                    <BuilderRowsSkeleton count={8} />
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
                                    <div
                                        className="pc-builder-row"
                                        id={`slot-${cat.id}`}
                                    >
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
                                                        {/*
                                                         * The builder's payload
                                                         * carries inStock, not
                                                         * stock_quantity, so
                                                         * this read undefined
                                                         * and every chosen part
                                                         * claimed to be out of
                                                         * stock.
                                                         */}
                                                        <span
                                                            className={`selected-product-badge${
                                                                stockLabel(
                                                                    selectedEntry.product,
                                                                ).tone
                                                            }`}
                                                        >
                                                            {
                                                                stockLabel(
                                                                    selectedEntry.product,
                                                                ).text
                                                            }
                                                        </span>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div
                                                    className={`empty-component-placeholder ${
                                                        cat.required
                                                            ? 'is-required'
                                                            : ''
                                                    }`}
                                                >
                                                    {cat.required
                                                        ? 'Needed for the build'
                                                        : 'No component selected'}
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

            <IncompleteBuildModal
                isOpen={gapPrompt}
                onClose={() => setGapPrompt(false)}
                onLocateMissing={() => {
                    setGapPrompt(false);
                    // The label says it takes you to them, so it should.
                    const first = essentials.missing[0];
                    if (first) {
                        document
                            .getElementById(`slot-${first.id}`)
                            ?.scrollIntoView({
                                behavior: scrollBehavior(),
                                block: 'center',
                            });
                    }
                }}
                onConfirm={addItemsToCart}
                missing={essentials.missing}
                adding={addingToCart}
            />

            {/* Official Branded Quotation Print / PDF Modal */}
            {/*
             * A builder item is { id, componentId, product } and carries no
             * slot name, so the quotation's Component column read
             * entry.category_name and printed nothing. The page holds the
             * categories, so it resolves the label here rather than teaching
             * the sheet how the builder stores its slots.
             */}
            <PcBuilderQuotationModal
                isOpen={quotationOpen}
                onClose={() => setQuotationOpen(false)}
                components={pcBuilderItems.map((item) => ({
                    ...item,
                    category_name:
                        categories.find((cat) => cat.id === item.componentId)
                            ?.name ?? item.componentId,
                }))}
                totalPrice={totalCost}
                estimatedWattage={estimatedWattage}
            />

            {/* Share PC Build Modal */}
            {/*
             * Hand-rolled overlay and card before this, against class names
             * that had no CSS anywhere — so there was no backdrop, no dialog
             * box and no positioning, and it rendered as bare text down the
             * left edge of the page under the footer. Modal already does all
             * of that, plus the escape key and the scroll lock.
             */}
            <Modal
                isOpen={shareModalOpen}
                onClose={() => setShareModalOpen(false)}
                title="Share Your Custom PC Build"
                maxWidth="540px"
            >
                <p className="share-link-help">
                    Anyone with this link can view and load your exact hardware
                    configuration:
                </p>
                <div className="share-link-row">
                    <input
                        type="text"
                        readOnly
                        value={shareUrl}
                        className="share-url-input"
                        onFocus={(e) => e.target.select()}
                        aria-label="Shareable build link"
                    />
                    <Button
                        variant="primary"
                        icon={copied ? Check : Copy}
                        onClick={handleCopyLink}
                    >
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                </div>
            </Modal>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
PcBuilderIndex.layout = mainLayout;
