import React, { useCallback, useEffect, useState } from 'react';
import { Search, Trash2, UserCheck, UserPlus } from 'lucide-react';
import Modal from '@/Components/Modal';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { formatBdt } from '@/utils/formatters';

const BLANK = {
    user_id: null,
    name: '',
    phone: '',
    street_address: '',
    city: '',
    zone: '',
    coupon_code: '',
};

/**
 * Taking an order at the counter or over the phone.
 *
 * The shop could only receive an order through the storefront, so somebody
 * ringing up had to be asked to go home and use the website — while stock,
 * payments, delivery, serials and the margin report all assume an order exists.
 *
 * Either kind of customer: search for an account and the order lands in their
 * history, or type a name and a number for a walk-in. Behind both it is the
 * same OrderService the storefront uses, so the stock check and the coupon
 * rules are the ones already known to work.
 */
export default function NewOrderModal({ open, onClose, onCreated }) {
    const [form, setForm] = useState(BLANK);
    const [lines, setLines] = useState([]);
    const [saving, setSaving] = useState(false);

    const [customerSearch, setCustomerSearch] = useState('');
    const [customers, setCustomers] = useState([]);
    const [chosen, setChosen] = useState(null);

    const [productSearch, setProductSearch] = useState('');
    const [products, setProducts] = useState([]);

    useEffect(() => {
        if (!open) return;
        setForm(BLANK);
        setLines([]);
        setChosen(null);
        setCustomerSearch('');
        setProductSearch('');
        setCustomers([]);
        setProducts([]);
    }, [open]);

    const findCustomers = useCallback(async (term) => {
        if (term.trim().length < 2) {
            setCustomers([]);
            return;
        }

        try {
            const res = await adminService.searchOrderCustomers(term);
            setCustomers(res?.data ?? []);
        } catch {
            setCustomers([]);
        }
    }, []);

    const findProducts = useCallback(async (term) => {
        if (term.trim().length < 2) {
            setProducts([]);
            return;
        }

        try {
            const res = await adminService.getStockUnits({ search: term });
            setProducts(res?.data ?? []);
        } catch {
            setProducts([]);
        }
    }, []);

    // Debounced, or every keystroke is a request.
    useEffect(() => {
        const t = setTimeout(() => findCustomers(customerSearch), 250);
        return () => clearTimeout(t);
    }, [customerSearch, findCustomers]);

    useEffect(() => {
        const t = setTimeout(() => findProducts(productSearch), 250);
        return () => clearTimeout(t);
    }, [productSearch, findProducts]);

    const set = (field) => (e) =>
        setForm((prev) => ({ ...prev, [field]: e.target.value }));

    /* Choosing an account fills what it knows and leaves the rest to be typed —
       the address on file may not be where this one is going. */
    const chooseCustomer = (customer) => {
        setChosen(customer);
        setForm((prev) => ({
            ...prev,
            user_id: customer.id,
            name: customer.name ?? prev.name,
            phone: customer.phone ?? prev.phone,
        }));
        setCustomers([]);
        setCustomerSearch('');
    };

    const forgetCustomer = () => {
        setChosen(null);
        setForm((prev) => ({ ...prev, user_id: null }));
    };

    const addLine = (product, variant = null) => {
        const key = `${product.id}:${variant?.id ?? ''}`;

        if (lines.some((l) => l.key === key)) return;

        setLines((prev) => [
            ...prev,
            {
                key,
                product_id: product.id,
                product_variant_id: variant?.id ?? null,
                name: variant
                    ? `${product.name} (${variant.name})`
                    : product.name,
                price: Number(
                    variant
                        ? variant.discount_price || variant.price
                        : product.discount_price || product.price,
                ),
                in_stock: variant
                    ? variant.stock_quantity
                    : product.stock_quantity,
                quantity: 1,
            },
        ]);
        setProductSearch('');
        setProducts([]);
    };

    const setQuantity = (key, value) =>
        setLines((prev) =>
            prev.map((l) =>
                l.key === key
                    ? { ...l, quantity: Math.max(1, Number(value) || 1) }
                    : l,
            ),
        );

    const goods = lines.reduce((sum, l) => sum + l.price * l.quantity, 0);

    const complete =
        form.name.trim() &&
        form.phone.trim() &&
        form.street_address.trim() &&
        form.city.trim() &&
        lines.length > 0;

    const save = async () => {
        setSaving(true);

        try {
            const res = await adminService.createOrder({
                ...form,
                coupon_code: form.coupon_code || null,
                lines: lines.map((l) => ({
                    product_id: l.product_id,
                    product_variant_id: l.product_variant_id,
                    quantity: l.quantity,
                })),
            });
            toast.success(res?.message || 'Order created.');
            onCreated();
        } catch (err) {
            toast.error(err?.message || 'Could not take that order.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            isOpen={open}
            onClose={onClose}
            title="Take an order"
            maxWidth="720px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        onClick={save}
                        loading={saving}
                        disabled={!complete}
                    >
                        Create order
                    </Button>
                </>
            }
        >
            <p className="admin-field-hint" style={{ marginBottom: 18 }}>
                For a customer at the counter or on the phone. Stock is reserved
                the moment this is created, exactly as it would be from the
                website. Record the payment separately when the money is taken.
            </p>

            {/* --- who it is for --- */}
            {chosen ? (
                <div className="no-chosen-customer">
                    <UserCheck size={15} />
                    <span>
                        <strong>{chosen.name}</strong>
                        <small>
                            {chosen.phone ?? chosen.email} · it will appear in
                            their account
                        </small>
                    </span>
                    <button type="button" onClick={forgetCustomer}>
                        Use different details
                    </button>
                </div>
            ) : (
                <>
                    <div className="no-search">
                        <Search size={14} />
                        <input
                            type="text"
                            value={customerSearch}
                            onChange={(e) => setCustomerSearch(e.target.value)}
                            placeholder="Find an existing customer by name, number or email…"
                        />
                    </div>

                    {customers.length > 0 && (
                        <div className="no-results">
                            {customers.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => chooseCustomer(c)}
                                >
                                    <UserPlus size={13} />
                                    <span>
                                        {c.name}
                                        <small>{c.phone ?? c.email}</small>
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}

                    <p
                        className="admin-field-hint"
                        style={{ marginBottom: 14 }}
                    >
                        Or leave that empty and type their details below — a
                        walk-in needs no account.
                    </p>
                </>
            )}

            <div className="form-row-2col">
                <FormInput
                    label="Name"
                    name="no_name"
                    required
                    value={form.name}
                    onChange={set('name')}
                    placeholder="Who the order is for"
                />
                <FormInput
                    label="Mobile"
                    name="no_phone"
                    required
                    value={form.phone}
                    onChange={set('phone')}
                    placeholder="017xxxxxxxx"
                    helperText="Where the rider rings and the order texts go."
                />
            </div>

            <FormInput
                label="Where it is going"
                name="no_street_address"
                required
                value={form.street_address}
                onChange={set('street_address')}
                placeholder="Street address, or the shop counter if collecting"
            />

            <div className="form-row-2col">
                <FormInput
                    label="City / District"
                    name="no_city"
                    required
                    value={form.city}
                    onChange={set('city')}
                    placeholder="e.g. Dhaka"
                />
                <FormInput
                    label="Zone / Thana"
                    name="no_zone"
                    value={form.zone}
                    onChange={set('zone')}
                    placeholder="Optional"
                />
            </div>

            {/* --- what they are buying --- */}
            <div className="no-search">
                <Search size={14} />
                <input
                    type="text"
                    value={productSearch}
                    onChange={(e) => setProductSearch(e.target.value)}
                    placeholder="Add a product…"
                />
            </div>

            {products.length > 0 && (
                <div className="no-results">
                    {products.slice(0, 8).map((p) =>
                        p.has_variants && p.variants?.length ? (
                            p.variants
                                .filter((v) => v.is_active)
                                .map((v) => (
                                    <button
                                        key={`${p.id}:${v.id}`}
                                        type="button"
                                        onClick={() => addLine(p, v)}
                                    >
                                        <span>
                                            {p.name} ({v.name})
                                            <small>
                                                {v.stock_quantity} in stock
                                            </small>
                                        </span>
                                    </button>
                                ))
                        ) : (
                            <button
                                key={p.id}
                                type="button"
                                onClick={() => addLine(p)}
                            >
                                <span>
                                    {p.name}
                                    <small>{p.stock_quantity} in stock</small>
                                </span>
                            </button>
                        ),
                    )}
                </div>
            )}

            {lines.length > 0 && (
                <table className="po-lines">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th className="po-num">Price</th>
                            <th className="po-num">Qty</th>
                            <th className="po-num">Line</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.key}>
                                <td>
                                    {l.name}
                                    {/* Said here as well as refused on save:
                                        better to see it before pressing than
                                        after. */}
                                    {l.quantity > l.in_stock && (
                                        <div className="cmp-failed">
                                            only {l.in_stock} in stock
                                        </div>
                                    )}
                                </td>
                                <td className="po-num">{formatBdt(l.price)}</td>
                                <td className="po-num">
                                    <input
                                        type="number"
                                        min="1"
                                        value={l.quantity}
                                        onChange={(e) =>
                                            setQuantity(l.key, e.target.value)
                                        }
                                    />
                                </td>
                                <td className="po-num">
                                    {formatBdt(l.price * l.quantity)}
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        className="admin-table-icon-btn"
                                        onClick={() =>
                                            setLines((prev) =>
                                                prev.filter(
                                                    (x) => x.key !== l.key,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 size={13} />
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colSpan={3}>Goods</td>
                            <td className="po-num" colSpan={2}>
                                <strong>{formatBdt(goods)}</strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            )}

            <FormInput
                label="Promo code"
                name="no_coupon_code"
                value={form.coupon_code}
                onChange={set('coupon_code')}
                placeholder="Optional"
                helperText="Checked and counted the same way it would be at checkout. Delivery and VAT are worked out on save."
            />
        </Modal>
    );
}
