import React, { useEffect, useState } from 'react';
import Modal from '../../../Components/Modal';
import Button from '../../../Components/Button';
import ProductImage from '../../../Components/ProductImage';
import axiosInstance from '../../../services/axiosInstance';
import { API_ENDPOINTS } from '../../../constants/endpoints';
import { formatBdt, formatDate } from '../../../utils/formatters';
import { Edit2, ExternalLink, AlertTriangle } from 'lucide-react';
import './ProductDetailsModal.css';

/**
 * Everything the shop knows about one product, read-only.
 *
 * The products table shows a name, a category, a brand, a price and a stock
 * figure. Anything else — which categories it is also listed under, what the
 * spec sheet says, whether the discount is currently running, how many options
 * it has, whether anyone has ordered it — could only be found by opening the
 * edit form and reading it back out of the inputs. That is slow, it is a form
 * that can be saved by accident, and half of it (orders, reviews, branch
 * holdings) is not in the form at all.
 *
 * Fetched on open rather than shipped with the table: the index is twenty rows
 * a page and none of this is needed until somebody asks for it.
 */

const Row = ({ label, children }) => (
    <div className="pd-row">
        <dt>{label}</dt>
        <dd>{children ?? <span className="pd-empty">Not set</span>}</dd>
    </div>
);

const Section = ({ title, children }) => (
    <section className="pd-section">
        <h4>{title}</h4>
        {children}
    </section>
);

export default function ProductDetailsModal({
    productId,
    isOpen,
    onClose,
    onEdit,
}) {
    const [product, setProduct] = useState(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState('');

    useEffect(() => {
        if (!isOpen || !productId) return;

        let cancelled = false;
        setLoading(true);
        setFailed('');

        axiosInstance
            .get(API_ENDPOINTS.ADMIN.PRODUCT_ITEM(productId))
            .then((res) => {
                if (!cancelled) setProduct(res?.data ?? res ?? null);
            })
            .catch((err) => {
                if (!cancelled)
                    setFailed(err?.message || 'Could not load that product.');
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [isOpen, productId]);

    // Cleared on close so reopening a different product never flashes the
    // previous one's details while the new request is in flight.
    useEffect(() => {
        if (!isOpen) setProduct(null);
    }, [isOpen]);

    const title = product ? product.name : 'Product details';

    /* Grouped the way the spec sheet is stored, so it reads like the product page. */
    const specGroups = (product?.specifications || []).reduce(
        (groups, spec) => {
            const key = spec.group || 'Specifications';
            (groups[key] = groups[key] || []).push(spec);
            return groups;
        },
        {},
    );

    return (
        <Modal isOpen={isOpen} onClose={onClose} title={title} maxWidth="920px">
            {loading && <p className="pd-loading">Loading…</p>}

            {failed && !loading && <p className="pd-failed">{failed}</p>}

            {product && !loading && (
                <div className="pd-body">
                    <header className="pd-head">
                        <ProductImage
                            product={product}
                            alt={product.name}
                            className="pd-thumb"
                        />

                        <div className="pd-head-text">
                            <div className="pd-badges">
                                <span
                                    className={`badge ${product.is_active ? 'badge-active' : 'badge-expired'}`}
                                >
                                    {product.is_active ? 'Live' : 'Hidden'}
                                </span>
                                <span
                                    className={`badge ${product.in_stock ? 'badge-stock-in' : 'badge-stock-danger'}`}
                                >
                                    {product.stock_status_label}
                                </span>
                                {product.has_discount && (
                                    <span className="badge badge-sale">
                                        {product.discount_window_open
                                            ? 'Discount running'
                                            : 'Discount scheduled'}
                                    </span>
                                )}
                                {product.needs_reorder && (
                                    <span className="badge badge-hot">
                                        Below reorder level
                                    </span>
                                )}
                            </div>

                            <p className="pd-price">
                                <strong>
                                    {formatBdt(product.effective_price)}
                                </strong>
                                {product.saving > 0 && (
                                    <>
                                        <s>{formatBdt(product.price)}</s>
                                        <em>
                                            Saves {formatBdt(product.saving)}
                                        </em>
                                    </>
                                )}
                            </p>

                            {product.missing_specs?.length > 0 && (
                                <p className="pd-warn">
                                    <AlertTriangle size={13} />
                                    The PC Builder cannot check compatibility
                                    without: {product.missing_specs.join(', ')}
                                </p>
                            )}
                        </div>
                    </header>

                    <div className="pd-columns">
                        <Section title="Identity">
                            <dl>
                                <Row label="Brand">{product.brand?.name}</Row>
                                <Row label="Model">{product.model}</Row>
                                <Row label="MPN">{product.mpn}</Row>
                                <Row label="Slug">{product.slug}</Row>
                                <Row label="Warranty">
                                    {product.warranty_text ||
                                        (product.warranty_months
                                            ? `${product.warranty_months} months`
                                            : null)}
                                </Row>
                            </dl>
                        </Section>

                        <Section title="Filed under">
                            <dl>
                                <Row label="Primary">
                                    {product.category?.name}
                                </Row>
                                <Row label="Also listed under">
                                    {/*
                                     * The pivot holds the primary too, so it is
                                     * filtered out here rather than repeated.
                                     */}
                                    {(product.categories || []).filter(
                                        (c) => c.id !== product.category_id,
                                    ).length > 0 ? (
                                        <span className="pd-chips">
                                            {product.categories
                                                .filter(
                                                    (c) =>
                                                        c.id !==
                                                        product.category_id,
                                                )
                                                .map((c) => (
                                                    <span
                                                        key={c.id}
                                                        className="pd-chip"
                                                    >
                                                        {c.parent?.name && (
                                                            <em>
                                                                {c.parent.name}{' '}
                                                                ›{' '}
                                                            </em>
                                                        )}
                                                        {c.name}
                                                    </span>
                                                ))}
                                        </span>
                                    ) : null}
                                </Row>
                            </dl>
                        </Section>

                        <Section title="Pricing">
                            <dl>
                                <Row label="Regular">
                                    {formatBdt(product.price)}
                                </Row>
                                <Row label="Discounted">
                                    {product.discount_price
                                        ? formatBdt(product.discount_price)
                                        : null}
                                </Row>
                                <Row label="Discount runs">
                                    {/*
                                     * Tested on the raw value: formatDate hands
                                     * back an em dash for null, so falling back
                                     * on its result printed "— → 7 Sept" where
                                     * the window has no start.
                                     */}
                                    {product.discount_starts_at ||
                                    product.discount_ends_at
                                        ? `${
                                              product.discount_starts_at
                                                  ? formatDate(
                                                        product.discount_starts_at,
                                                    )
                                                  : 'now'
                                          } → ${
                                              product.discount_ends_at
                                                  ? formatDate(
                                                        product.discount_ends_at,
                                                    )
                                                  : 'no end date'
                                          }`
                                        : null}
                                </Row>
                                <Row label="EMI from">
                                    {product.emi_monthly
                                        ? `${formatBdt(product.emi_monthly)} / month`
                                        : null}
                                </Row>
                            </dl>

                            {product.quantity_discounts?.length > 0 && (
                                <table className="pd-table">
                                    <thead>
                                        <tr>
                                            <th>From</th>
                                            <th>Unit price</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {product.quantity_discounts.map((t) => (
                                            <tr key={t.id}>
                                                <td>{t.min_quantity}+</td>
                                                <td>{formatBdt(t.price)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </Section>

                        <Section title="Stock">
                            <dl>
                                <Row label="On hand">
                                    {product.stock_quantity}
                                </Row>
                                <Row label="Reorder level">
                                    {product.reorder_level_effective}
                                </Row>
                                <Row label="Movements recorded">
                                    {/*
                                     * Zero here is the tell that a quantity was
                                     * written straight onto the row rather than
                                     * bought — see stock:reconcile-opening.
                                     */}
                                    {product.stock_movements_count === 0 ? (
                                        <span className="pd-warn-inline">
                                            None — this quantity has no history
                                        </span>
                                    ) : (
                                        product.stock_movements_count
                                    )}
                                </Row>
                                <Row label="Held at">
                                    {product.stock_levels?.length > 0 ? (
                                        <span className="pd-chips">
                                            {product.stock_levels.map((s) => (
                                                <span
                                                    key={s.id}
                                                    className="pd-chip"
                                                >
                                                    {s.store?.name || 'Branch'}:{' '}
                                                    {s.quantity}
                                                </span>
                                            ))}
                                        </span>
                                    ) : null}
                                </Row>
                            </dl>
                        </Section>

                        <Section title="Activity">
                            <dl>
                                <Row label="Ordered">
                                    {product.order_items_count} time(s)
                                </Row>
                                <Row label="Reviews">
                                    {product.reviews_count}
                                    {product.reviews_count > 0 &&
                                        ` · ${product.average_rating} ★`}
                                </Row>
                                <Row label="Questions">
                                    {product.questions_count}
                                </Row>
                                <Row label="Views">{product.views_count}</Row>
                                <Row label="Added">
                                    {formatDate(product.created_at)}
                                </Row>
                                <Row label="Last edited">
                                    {formatDate(product.updated_at)}
                                </Row>
                            </dl>
                        </Section>

                        <Section title="Search listing">
                            <dl>
                                <Row label="Meta title">
                                    {product.meta_title}
                                </Row>
                                <Row label="Meta description">
                                    {product.meta_description}
                                </Row>
                            </dl>
                        </Section>
                    </div>

                    {product.variants?.length > 0 && (
                        <Section title={`Options (${product.variants.length})`}>
                            <div className="pd-scroll">
                                <table className="pd-table">
                                    <thead>
                                        <tr>
                                            <th>Option</th>
                                            <th>SKU</th>
                                            <th>Price</th>
                                            <th>Stock</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {product.variants.map((v) => (
                                            <tr key={v.id}>
                                                <td>
                                                    {Object.values(
                                                        v.options || {},
                                                    ).join(' · ') || '—'}
                                                </td>
                                                <td>{v.sku || '—'}</td>
                                                <td>
                                                    {v.price
                                                        ? formatBdt(v.price)
                                                        : '—'}
                                                </td>
                                                <td>{v.stock_quantity}</td>
                                                <td>
                                                    {v.is_active
                                                        ? 'Active'
                                                        : 'Hidden'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Section>
                    )}

                    {Object.keys(specGroups).length > 0 && (
                        <Section title="Specifications">
                            {Object.entries(specGroups).map(([group, rows]) => (
                                <div key={group} className="pd-spec-group">
                                    <h5>{group}</h5>
                                    <dl>
                                        {rows.map((spec) => (
                                            <Row
                                                key={spec.id}
                                                label={spec.name}
                                            >
                                                {spec.value}
                                            </Row>
                                        ))}
                                    </dl>
                                </div>
                            ))}
                        </Section>
                    )}

                    {product.key_features && (
                        <Section title="Key features">
                            <div
                                className="pd-rich"
                                dangerouslySetInnerHTML={{
                                    __html: product.key_features,
                                }}
                            />
                        </Section>
                    )}

                    {product.description && (
                        <Section title="Description">
                            <div
                                className="pd-rich"
                                dangerouslySetInnerHTML={{
                                    __html: product.description,
                                }}
                            />
                        </Section>
                    )}

                    {product.related_products?.length > 0 && (
                        <Section title="Shown alongside">
                            <span className="pd-chips">
                                {product.related_products.map((r) => (
                                    <span key={r.id} className="pd-chip">
                                        {r.name}
                                    </span>
                                ))}
                            </span>
                        </Section>
                    )}

                    <div className="pd-actions">
                        <Button
                            variant="outline"
                            icon={ExternalLink}
                            onClick={() =>
                                window.open(
                                    `/products/${product.slug}`,
                                    '_blank',
                                )
                            }
                        >
                            View on site
                        </Button>
                        <Button
                            icon={Edit2}
                            onClick={() => {
                                onClose();
                                onEdit?.(product);
                            }}
                        >
                            Edit product
                        </Button>
                    </div>
                </div>
            )}
        </Modal>
    );
}
