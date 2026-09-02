import React, { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import Button from './Button';
import { toast } from './Toast';
import { cartService, productService } from '../services';
import useAppStore from '../store/useAppStore';
import { formatBdt } from '../utils/formatters';
import { ROUTES } from '../constants/endpoints';
import './VariantPickerModal.css';

/**
 * Choosing an option from a card, without leaving the list.
 *
 * A product sold by option cannot be added to the cart from a card: the server
 * is asked for a product and an option, and refuses a product alone. The cards
 * used to answer that by navigating to the product page, which loses the
 * shopper's place — the filters, the scroll, the page they were reading.
 *
 * Mounted once by the layout. Every card on every page raises it through the
 * store rather than each page owning a copy.
 */
export default function VariantPickerModal() {
    const { slug, name, thenCheckout } = useAppStore((s) => s.variantPicker);
    const close = useAppStore((s) => s.closeVariantPicker);

    const [loading, setLoading] = useState(false);
    const [product, setProduct] = useState(null);
    const [chosenId, setChosenId] = useState(null);
    const [adding, setAdding] = useState(false);
    const [failed, setFailed] = useState(false);

    const open = Boolean(slug);

    useEffect(() => {
        if (!open) {
            setProduct(null);
            setChosenId(null);
            setFailed(false);
            return;
        }

        let live = true;
        setLoading(true);
        setFailed(false);

        productService
            .getProductBySlug(slug)
            .then((data) => {
                if (!live) return;

                setProduct(data);

                /*
                 * Opens on the first option that can actually be bought.
                 * Landing on a sold-out one asks the shopper to discover that
                 * by pressing a disabled button.
                 */
                const options = data?.active_variants || [];
                const firstInStock = options.find(
                    (v) => Number(v.stock_quantity) > 0,
                );

                setChosenId((firstInStock || options[0])?.id ?? null);
            })
            .catch(() => {
                if (live) setFailed(true);
            })
            .finally(() => {
                if (live) setLoading(false);
            });

        return () => {
            live = false;
        };
    }, [open, slug]);

    // Escape closes it, the way every other modal in the shop behaves.
    useEffect(() => {
        if (!open) return undefined;

        const onKey = (e) => {
            if (e.key === 'Escape') close();
        };

        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, close]);

    const variants = product?.active_variants || [];
    const chosen = variants.find((v) => v.id === chosenId) || null;

    const confirm = useCallback(async () => {
        if (!product || !chosen || adding) return;

        setAdding(true);
        try {
            await cartService.addToCart(product.id, 1, chosen.id);
            useAppStore.getState().fetchCartCount();
            toast.success(`Added "${product.name} — ${chosen.name}" to your cart.`);
            close();

            // Buy Now asked to go on to checkout; the cart icon did not.
            if (thenCheckout) router.visit(ROUTES.CHECKOUT);
        } catch (error) {
            toast.error(error?.message || 'Could not add that to your cart.');
        } finally {
            setAdding(false);
        }
    }, [product, chosen, adding, thenCheckout, close]);

    if (!open) return null;

    return (
        <div
            className="variant-picker-backdrop"
            role="presentation"
            onClick={close}
        >
            <div
                className="variant-picker-panel"
                role="dialog"
                aria-modal="true"
                aria-label={`Choose an option for ${product?.name || name || 'this product'}`}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="variant-picker-head">
                    <h2 className="variant-picker-title">
                        {product?.name || name || 'Choose an option'}
                    </h2>
                    <button
                        type="button"
                        className="variant-picker-close"
                        onClick={close}
                        aria-label="Close"
                    >
                        <X size={18} />
                    </button>
                </div>

                {loading && (
                    <p className="variant-picker-note">Loading options…</p>
                )}

                {failed && (
                    <div className="variant-picker-note">
                        <p>We could not load the options for this product.</p>
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() =>
                                router.visit(ROUTES.PRODUCT_DETAIL(slug))
                            }
                        >
                            Open the product page
                        </Button>
                    </div>
                )}

                {!loading && !failed && variants.length === 0 && (
                    <p className="variant-picker-note">
                        This product has no options to choose from right now.
                    </p>
                )}

                {!loading && !failed && variants.length > 0 && (
                    <>
                        <ul className="variant-picker-list">
                            {variants.map((variant) => {
                                const out =
                                    Number(variant.stock_quantity) === 0;

                                return (
                                    <li key={variant.id}>
                                        <button
                                            type="button"
                                            disabled={out}
                                            className={`variant-picker-option ${
                                                variant.id === chosenId
                                                    ? 'is-chosen'
                                                    : ''
                                            } ${out ? 'is-out' : ''}`}
                                            onClick={() =>
                                                setChosenId(variant.id)
                                            }
                                        >
                                            <span className="variant-picker-name">
                                                {variant.name}
                                            </span>
                                            <span className="variant-picker-price">
                                                {formatBdt(
                                                    variant.effective_price,
                                                )}
                                            </span>
                                            {out && (
                                                <span className="variant-picker-out">
                                                    Sold out
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>

                        <div className="variant-picker-actions">
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    router.visit(ROUTES.PRODUCT_DETAIL(slug))
                                }
                            >
                                Full details
                            </Button>
                            <Button
                                onClick={confirm}
                                disabled={!chosen || adding}
                            >
                                {adding
                                    ? 'Adding…'
                                    : thenCheckout
                                      ? 'Buy now'
                                      : 'Add to cart'}
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
