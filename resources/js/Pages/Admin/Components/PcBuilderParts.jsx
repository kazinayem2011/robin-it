import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ArrowDown,
    ArrowUp,
    Eye,
    EyeOff,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import Button from '@/Components/Button';
import Modal from '@/Components/Modal';
import FormInput from '@/Components/FormInput';
import CategoryPicker from '@/Components/CategoryPicker';
import { Checkbox } from '@/Components/Checkbox';
import { toast } from '@/Components/Toast';
import axiosInstance from '@/services/axiosInstance';
import { API_ENDPOINTS, ROUTES } from '@/constants/endpoints';
import { pcBuilderIcon } from '@/utils/pcBuilderIcons';

const blank = {
    name: '',
    categories: [],
    icon: 'Package',
    group: 'peripherals',
    is_required: false,
    hint: '',
    max_quantity: 1,
    is_active: true,
};

const reload = () =>
    router.reload({
        only: ['parts', 'slots', 'problems'],
        preserveScroll: true,
    });

/**
 * The PC Builder's parts: add one (Anti Virus, UPS), change it, order them.
 *
 * They were a list in the code, so the shop could not offer something new in
 * the builder without a developer. The seven parts the compatibility check
 * reads can be changed or hidden, not deleted.
 */
export default function PcBuilderParts({ parts = [], icons = [] }) {
    const [editing, setEditing] = useState(null); // null, 'new', or a part
    const [busy, setBusy] = useState(null);

    const move = async (part, direction) => {
        setBusy(part.id);
        try {
            await axiosInstance.post(
                API_ENDPOINTS.ADMIN.PC_BUILDER_PART_MOVE(part.id),
                { direction },
            );
            reload();
        } catch (err) {
            toast.error(err?.message || 'Could not move that part.');
        } finally {
            setBusy(null);
        }
    };

    const toggle = async (part) => {
        setBusy(part.id);
        try {
            await axiosInstance.put(
                API_ENDPOINTS.ADMIN.PC_BUILDER_PART(part.id),
                {
                    ...payloadOf(part),
                    is_active: !part.is_active,
                },
            );
            toast.success(
                part.is_active
                    ? `${part.name} is hidden from the builder.`
                    : `${part.name} is shown in the builder.`,
            );
            reload();
        } catch (err) {
            toast.error(err?.message || 'Could not change that part.');
        } finally {
            setBusy(null);
        }
    };

    const remove = async (part) => {
        if (
            !window.confirm(
                `Remove ${part.name} from the PC Builder? Customers will no longer see it. Products are not affected.`,
            )
        ) {
            return;
        }
        setBusy(part.id);
        try {
            const res = await axiosInstance.delete(
                API_ENDPOINTS.ADMIN.PC_BUILDER_PART(part.id),
            );
            toast.success(res?.message || `${part.name} removed.`);
            reload();
        } catch (err) {
            toast.error(err?.message || 'Could not remove that part.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <section className="admin-card admin-pcb-parts">
            <div className="admin-card-header">
                <div>
                    <h2 className="admin-card-title-inline">
                        Parts in the builder
                    </h2>
                    <p className="admin-field-hint">
                        What a customer picks, in this order. Add a part to
                        offer something new, like Anti Virus. Parts marked{' '}
                        <strong>Checked for fit</strong> are the ones the
                        builder checks fit together (processor and motherboard,
                        RAM and motherboard…), so they can be hidden but not
                        deleted.
                    </p>
                </div>
                <Button icon={Plus} onClick={() => setEditing('new')}>
                    Add a part
                </Button>
            </div>

            <div className="admin-pcb-parts-scroll">
                <table className="admin-pcb-table admin-pcb-parts-table">
                    <thead>
                        <tr>
                            <th className="admin-pcb-order">Order</th>
                            <th>Part</th>
                            <th>Products from</th>
                            <th className="num">Up to</th>
                            <th className="num">Products</th>
                            <th>Specs</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {parts.map((part, i) => {
                            const Icon = pcBuilderIcon(part.icon);
                            return (
                                <tr
                                    key={part.id}
                                    className={
                                        part.is_active ? '' : 'is-hidden'
                                    }
                                >
                                    <td className="admin-pcb-order">
                                        <div className="admin-pcb-icon-row">
                                            <button
                                                type="button"
                                                className="admin-table-icon-btn"
                                                aria-label={`Move ${part.name} up`}
                                                disabled={
                                                    i === 0 || busy === part.id
                                                }
                                                onClick={() => move(part, 'up')}
                                            >
                                                <ArrowUp size={13} />
                                            </button>
                                            <button
                                                type="button"
                                                className="admin-table-icon-btn"
                                                aria-label={`Move ${part.name} down`}
                                                disabled={
                                                    i === parts.length - 1 ||
                                                    busy === part.id
                                                }
                                                onClick={() =>
                                                    move(part, 'down')
                                                }
                                            >
                                                <ArrowDown size={13} />
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <div className="admin-pcb-part-name">
                                            <Icon size={16} />
                                            <strong>{part.name}</strong>
                                            {part.is_required && (
                                                <span className="admin-pcb-required">
                                                    required
                                                </span>
                                            )}
                                            {!part.is_active && (
                                                <span className="admin-pcb-hidden-tag">
                                                    hidden
                                                </span>
                                            )}
                                        </div>
                                        <div className="admin-pcb-part-meta">
                                            {part.group === 'core'
                                                ? 'Main part'
                                                : 'Extra'}
                                            {part.checks_compatibility && (
                                                <span className="admin-pcb-compat">
                                                    <ShieldCheck size={12} />{' '}
                                                    Checked for fit
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="admin-pcb-path">
                                        {part.categories.length
                                            ? part.categories
                                                  .map((c) => c.name)
                                                  .join(', ')
                                            : '—'}
                                    </td>
                                    <td className="num">{part.max_quantity}</td>
                                    <td className="num">
                                        <ProductsCell part={part} />
                                    </td>
                                    <td>
                                        <SpecsCell part={part} />
                                    </td>
                                    <td className="admin-pcb-part-actions">
                                        <div className="admin-pcb-icon-row">
                                            <button
                                                type="button"
                                                className="admin-table-icon-btn"
                                                title={
                                                    part.is_active
                                                        ? 'Hide from the builder'
                                                        : 'Show in the builder'
                                                }
                                                aria-label={`${part.is_active ? 'Hide' : 'Show'} ${part.name}`}
                                                disabled={busy === part.id}
                                                onClick={() => toggle(part)}
                                            >
                                                {part.is_active ? (
                                                    <Eye size={14} />
                                                ) : (
                                                    <EyeOff size={14} />
                                                )}
                                            </button>
                                            <button
                                                type="button"
                                                className="admin-table-icon-btn"
                                                title="Edit"
                                                aria-label={`Edit ${part.name}`}
                                                onClick={() => setEditing(part)}
                                            >
                                                <Pencil size={14} />
                                            </button>
                                            {!part.checks_compatibility && (
                                                <button
                                                    type="button"
                                                    className="admin-table-icon-btn has-label"
                                                    title="Remove from the builder"
                                                    aria-label={`Remove ${part.name}`}
                                                    disabled={busy === part.id}
                                                    onClick={() => remove(part)}
                                                >
                                                    <Trash2 size={14} />
                                                    <span>Remove</span>
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <PartForm
                part={editing}
                icons={icons}
                onClose={() => setEditing(null)}
                onSaved={() => {
                    setEditing(null);
                    reload();
                }}
            />
        </section>
    );
}

/** How many a customer can choose from, and how many are in stock. */
function ProductsCell({ part }) {
    if (!part.is_active) {
        return <span className="admin-pcb-muted">hidden</span>;
    }
    // A required part with nothing in it stops every build.
    if (part.starved) {
        return (
            <span className="admin-pcb-warn">
                <AlertTriangle size={13} /> none
            </span>
        );
    }
    if (!part.products) {
        return <span className="admin-pcb-muted">none, not shown</span>;
    }
    return (
        <span>
            {part.products}
            <span className="admin-pcb-muted"> · {part.in_stock} in stock</span>
        </span>
    );
}

/** The specs the fit check reads from these products, and who lacks them. */
function SpecsCell({ part }) {
    if (!part.needs_specs?.length) {
        return <span className="admin-pcb-muted">—</span>;
    }
    if (!part.missing_specs) {
        return (
            <span className="admin-pcb-ok">
                <CheckCircle2 size={13} /> all set
            </span>
        );
    }
    const href =
        `${ROUTES.ADMIN_PRODUCTS}?needs_specs=1` +
        (part.first_category_id
            ? `&category_id=${part.first_category_id}`
            : '');
    return (
        <Link href={href} className="admin-pcb-warn">
            {part.missing_specs} of {part.products} missing{' '}
            {part.needs_specs.join(', ')}
        </Link>
    );
}

function payloadOf(form) {
    return {
        name: form.name,
        category_ids: (form.categories ?? []).map((c) => c.id),
        icon: form.icon,
        group: form.group,
        is_required: Boolean(form.is_required),
        hint: form.hint || null,
        max_quantity: Number(form.max_quantity) || 1,
        is_active: Boolean(form.is_active),
    };
}

/** Adding a part, or changing one. */
function PartForm({ part, icons, onClose, onSaved }) {
    const isNew = part === 'new';
    const [form, setForm] = useState(blank);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    React.useEffect(() => {
        if (!part) return;
        setErrors({});
        setForm(
            isNew
                ? blank
                : {
                      ...blank,
                      ...part,
                      hint: part.hint ?? '',
                  },
        );
    }, [part, isNew]);

    const set = (field, value) => setForm((f) => ({ ...f, [field]: value }));

    const addCategory = (category) =>
        setForm((f) =>
            f.categories.some((c) => c.id === category.id)
                ? f
                : {
                      ...f,
                      categories: [
                          ...f.categories,
                          {
                              id: category.id,
                              name: category.name,
                              path: category.path ?? '',
                          },
                      ],
                  },
        );

    const save = async () => {
        setSaving(true);
        setErrors({});
        try {
            const res = isNew
                ? await axiosInstance.post(
                      API_ENDPOINTS.ADMIN.PC_BUILDER_PARTS,
                      payloadOf(form),
                  )
                : await axiosInstance.put(
                      API_ENDPOINTS.ADMIN.PC_BUILDER_PART(part.id),
                      payloadOf(form),
                  );
            toast.success(res?.message || 'Saved.');
            onSaved();
        } catch (err) {
            setErrors(err?.errors ?? {});
            toast.error(err?.message || 'Could not save that part.');
        } finally {
            setSaving(false);
        }
    };

    const firstError = (key) => {
        const e = errors[key];
        return Array.isArray(e) ? e[0] : e;
    };

    return (
        <Modal
            isOpen={Boolean(part)}
            onClose={onClose}
            title={
                isNew ? 'Add a part to the PC Builder' : `Edit ${part?.name}`
            }
            maxWidth="640px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save} loading={saving}>
                        {isNew ? 'Add part' : 'Save part'}
                    </Button>
                </>
            }
        >
            <FormInput
                label="Name customers see"
                name="part_name"
                required
                value={form.name}
                onChange={(e) => set('name', e.target.value)}
                placeholder="e.g. Anti Virus"
                error={firstError('name')}
            />

            <CategoryPicker
                id="part_categories"
                label="Products come from these categories"
                required
                multiple
                chips={form.categories}
                value={form.categories.map((c) => c.id)}
                onChange={addCategory}
                onRemove={(id) =>
                    set(
                        'categories',
                        form.categories.filter((c) => c.id !== id),
                    )
                }
                error={firstError('category_ids')}
                helperText="Everything under a chosen category is included too. Pick more than one if the part lives on several shelves, like SSD and Hard Disk for Storage."
            />

            <fieldset className="admin-pcb-icon-field">
                <legend>Icon</legend>
                <div className="admin-pcb-icon-grid">
                    {icons.map((name) => {
                        const Icon = pcBuilderIcon(name);
                        return (
                            <button
                                key={name}
                                type="button"
                                className={form.icon === name ? 'is-on' : ''}
                                aria-label={name}
                                aria-pressed={form.icon === name}
                                title={name}
                                onClick={() => set('icon', name)}
                            >
                                <Icon size={18} />
                            </button>
                        );
                    })}
                </div>
            </fieldset>

            <fieldset className="admin-pcb-kind">
                <legend>Kind</legend>
                <label>
                    <input
                        type="radio"
                        name="part_group"
                        checked={form.group === 'core'}
                        onChange={() => set('group', 'core')}
                    />{' '}
                    Main part <span>— inside the PC</span>
                </label>
                <label>
                    <input
                        type="radio"
                        name="part_group"
                        checked={form.group === 'peripherals'}
                        onChange={() => set('group', 'peripherals')}
                    />{' '}
                    Extra <span>— monitor, UPS, software</span>
                </label>
            </fieldset>

            <div className="admin-grid-equal-2col">
                <FormInput
                    placeholder="e.g. 1"
                    label="A customer can choose up to"
                    name="part_max"
                    type="number"
                    min="1"
                    max="10"
                    value={form.max_quantity}
                    onChange={(e) => set('max_quantity', e.target.value)}
                    helperText="1 for most parts; more for RAM sticks or drives."
                    error={firstError('max_quantity')}
                />
                <FormInput
                    label="Short hint (optional)"
                    name="part_hint"
                    value={form.hint}
                    onChange={(e) => set('hint', e.target.value)}
                    placeholder="e.g. Protects the PC from day one"
                    error={firstError('hint')}
                />
            </div>

            {/* One per line: side by side they ran into each other. */}
            <div className="admin-pcb-checks">
                <Checkbox
                    name="part_required"
                    label="Required — a build is not complete without it"
                    checked={Boolean(form.is_required)}
                    onChange={(e) => set('is_required', e.target.checked)}
                />
                <Checkbox
                    name="part_active"
                    label="Shown in the builder"
                    checked={Boolean(form.is_active)}
                    onChange={(e) => set('is_active', e.target.checked)}
                />
            </div>
        </Modal>
    );
}
