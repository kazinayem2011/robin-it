import React, { useState, useRef, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import DataTable from '../../Components/DataTable';
import FormInput from '../../Components/FormInput';
import { FormSelect } from '../../Components/FormSelect';
import Modal from '../../Components/Modal';
import ConfirmDialog from '../../Components/ConfirmDialog';
import CategoryPicker from '../../Components/CategoryPicker';
import { toast } from '../../Components/Toast';
import { API_ENDPOINTS } from '../../constants/endpoints';
import axiosInstance from '../../services/axiosInstance';
import {
    SlidersHorizontal,
    Plus,
    Edit2,
    Trash2,
    GripVertical,
    X,
    AlertTriangle,
    Copy,
} from 'lucide-react';

/**
 * The questions the filter sidebar asks, and the answers it offers.
 *
 * There was no screen for this at all: the sixty-six filters existed because a
 * seeder made them, and nothing in the application could add a sixty-seventh.
 * A new category could be given products, photos and a full spec sheet and
 * still be unfilterable.
 *
 * These are not the spec sheet, and the difference is the whole reason the
 * table exists. A specification is prose written for one product — "3.4GHz to
 * 4.6GHz" — and filtering on it makes every distinct string its own checkbox.
 * A filter value is a controlled answer two products can share exactly.
 */

const TYPES = [
    {
        value: 'enum',
        label: 'One answer from a list',
        hint: 'Wi-Fi 6, IPS, Dual Band. The shopper picks from the answers below.',
    },
    {
        value: 'number',
        label: 'A number, shown as bands',
        hint: 'Each answer covers a range: "301 Mbps to 750 Mbps". Leave one end open for "Up to 300".',
    },
    {
        value: 'flags',
        label: 'Many answers at once',
        hint: 'USB Port, Parental Controls, Mesh Support. A product may carry several.',
    },
];

const blankValue = () => ({
    key: `new-${Math.random().toString(36).slice(2)}`,
    id: null,
    label: '',
    range_from: '',
    range_to: '',
    products_count: 0,
});

const emptyForm = () => ({
    name: '',
    input_type: 'enum',
    unit: '',
    sort_order: 0,
    categories: [],
    values: [blankValue()],
});

export default function AdminAttributes({
    attributes = [],
    filters = {},
    counts = {},
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [confirming, setConfirming] = useState(null);
    const [search, setSearch] = useState(filters.search || '');
    const searchTimer = useRef(null);

    useEffect(() => () => clearTimeout(searchTimer.current), []);

    // Debounced like every other admin search: a request per keystroke
    // reorders itself on a slow connection and leaves the wrong list up.
    const onSearch = (term) => {
        setSearch(term);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => {
            router.get(
                API_ENDPOINTS.ADMIN.ATTRIBUTES,
                { search: term },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 350);
    };

    const isNumber = form.input_type === 'number';

    const openCreate = () => {
        setEditing(null);
        setForm(emptyForm());
        setErrors({});
        setModalOpen(true);
    };

    const openEdit = (attribute) => {
        setEditing(attribute);
        setErrors({});
        setForm({
            name: attribute.name || '',
            input_type: attribute.input_type || 'enum',
            unit: attribute.unit || '',
            sort_order: attribute.sort_order ?? 0,
            categories: attribute.categories || [],
            values: (attribute.values || []).map((v) => ({
                key: `v-${v.id}`,
                id: v.id,
                label: v.label || '',
                range_from: v.range_from ?? '',
                range_to: v.range_to ?? '',
                products_count: v.products_count ?? 0,
            })),
        });
        setModalOpen(true);
    };

    const setValue = (key, patch) =>
        setForm((f) => ({
            ...f,
            values: f.values.map((v) =>
                v.key === key ? { ...v, ...patch } : v,
            ),
        }));

    const addValue = () =>
        setForm((f) => ({ ...f, values: [...f.values, blankValue()] }));

    const removeValue = (key) =>
        setForm((f) => ({
            ...f,
            values: f.values.filter((v) => v.key !== key),
        }));

    /*
     * Dragging an answer up or down the list.
     *
     * The order is the order the sidebar draws them in, and it is sent as each
     * row's position on save — so this only rearranges the array and nothing
     * is written until the form is saved.
     *
     * Held in a ref rather than state because it changes on every pointer move
     * across a row and none of it is drawn; `draggingKey` is the only part the
     * page renders from, and only to fade the row being carried.
     */
    const dragRef = useRef(null);
    const [draggingKey, setDraggingKey] = useState(null);

    /*
     * The row is only draggable while the pointer is on the handle. Marking
     * the whole row draggable would mean a drag starts the moment anyone tries
     * to select text in the label beside it.
     */
    const [armedKey, setArmedKey] = useState(null);

    const moveValue = (from, to) =>
        setForm((f) => {
            if (to < 0 || to >= f.values.length || from === to) return f;

            const values = [...f.values];
            const [row] = values.splice(from, 1);
            values.splice(to, 0, row);

            return { ...f, values };
        });

    const startDrag = (key) => {
        const from = form.values.findIndex((v) => v.key === key);
        if (from === -1) return;

        dragRef.current = { key, to: from };
        setDraggingKey(key);
    };

    // Crossing a row rearranges it there and then, so the list reads the way
    // it will end up rather than the way it started.
    const dragOver = (index) => {
        const drag = dragRef.current;
        if (!drag || drag.to === index) return;

        moveValue(drag.to, index);
        drag.to = index;
    };

    const endDrag = () => {
        dragRef.current = null;
        setDraggingKey(null);
        setArmedKey(null);
    };

    /*
     * The same move from the keyboard. A handle that can only be dragged is a
     * control somebody navigating by keyboard cannot reach at all.
     */
    const onHandleKey = (event, index) => {
        const step =
            event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0;

        if (step === 0) return;

        event.preventDefault();
        moveValue(index, index + step);
    };

    const addCategory = (category) =>
        setForm((f) =>
            f.categories.some((c) => c.id === category.id)
                ? f
                : {
                      ...f,
                      categories: [
                          ...f.categories,
                          // The ancestry comes back with the search result and
                          // is kept: four shelves are called Asus, and a chip
                          // reading "Asus" names none of them.
                          {
                              id: category.id,
                              name: category.name,
                              path: category.path || '',
                          },
                      ],
                  },
        );

    const removeCategory = (id) =>
        setForm((f) => ({
            ...f,
            categories: f.categories.filter((c) => c.id !== id),
        }));

    const save = async () => {
        setSaving(true);
        setErrors({});

        const payload = {
            name: form.name,
            input_type: form.input_type,
            unit: isNumber ? form.unit : null,
            sort_order: Number(form.sort_order) || 0,
            category_ids: form.categories.map((c) => c.id),
            values: form.values.map((v, index) => ({
                id: v.id ?? undefined,
                label: v.label,
                sort_order: index,
                range_from:
                    isNumber && v.range_from !== '' ? v.range_from : null,
                range_to: isNumber && v.range_to !== '' ? v.range_to : null,
            })),
        };

        try {
            if (editing) {
                await axiosInstance.patch(
                    API_ENDPOINTS.ADMIN.ATTRIBUTE_ITEM(editing.id),
                    payload,
                );
                toast.success(`Filter "${form.name}" updated.`);
            } else {
                await axiosInstance.post(
                    API_ENDPOINTS.ADMIN.ATTRIBUTES,
                    payload,
                );
                toast.success(`Filter "${form.name}" created.`);
            }
            setModalOpen(false);
            router.reload({ only: ['attributes', 'counts'] });
        } catch (error) {
            // The server names the row it is unhappy about — values.2.label —
            // and that is the one complaint worth putting beside the row
            // rather than in a toast that scrolls away.
            setErrors(error?.errors || {});
            toast.error(error?.message || 'Could not save that filter.');
        } finally {
            setSaving(false);
        }
    };

    /*
     * Ask the same question on another shelf.
     *
     * Twenty-nine of the sixty-six filters here are already a repeat of
     * another by name, so this is nearly half of what the screen is for. The
     * copy arrives attached to nothing — the shelves are the one thing that
     * differs — and opens straight away, since choosing them is the whole
     * remaining decision.
     */
    const [copyingId, setCopyingId] = useState(null);

    const duplicate = async (attribute) => {
        setCopyingId(attribute.id);

        try {
            const response = await axiosInstance.post(
                API_ENDPOINTS.ADMIN.ATTRIBUTE_DUPLICATE(attribute.id),
            );

            toast.success(response?.message || 'Copied.');

            if (response?.data) openEdit(response.data);

            router.reload({ only: ['attributes', 'counts'] });
        } catch (error) {
            toast.error(error?.message || 'Could not copy that filter.');
        } finally {
            setCopyingId(null);
        }
    };

    const remove = async () => {
        const attribute = confirming;
        if (!attribute) return;

        try {
            await axiosInstance.delete(
                API_ENDPOINTS.ADMIN.ATTRIBUTE_ITEM(attribute.id),
            );
            toast.success('Filter deleted.');
            router.reload({ only: ['attributes', 'counts'] });
        } catch (error) {
            toast.error(error?.message || 'Could not delete that filter.');
        } finally {
            setConfirming(null);
        }
    };

    const tagged = (attribute) =>
        (attribute.values || []).reduce(
            (sum, v) => sum + (v.products_count || 0),
            0,
        );

    const columns = [
        {
            key: 'name',
            header: 'Filter',
            render: (a) => (
                <div className="admin-attr-name-cell">
                    <strong>{a.name}</strong>
                    <small>
                        {a.slug}
                        {a.unit ? ` · ${a.unit}` : ''}
                    </small>
                </div>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            render: (a) => (
                <span className="badge badge-new">
                    {TYPES.find((t) => t.value === a.input_type)?.label ||
                        a.input_type}
                </span>
            ),
        },
        {
            key: 'values',
            header: 'Answers',
            render: (a) => (
                <span className="admin-attr-answer-preview">
                    {(a.values || [])
                        .slice(0, 3)
                        .map((v) => v.label)
                        .join(', ')}
                    {(a.values || []).length > 3
                        ? ` +${a.values.length - 3} more`
                        : ''}
                </span>
            ),
        },
        {
            key: 'shelves',
            header: 'Shown on',
            render: (a) =>
                (a.categories || []).length > 0 ? (
                    <span className="admin-attr-shelf-list">
                        {a.categories
                            .map((c) =>
                                c.path ? `${c.path} › ${c.name}` : c.name,
                            )
                            .join(', ')}
                    </span>
                ) : (
                    /* Named rather than left blank: this is precisely why a
                       filter that looks complete is never offered to anyone. */
                    <span className="admin-attr-unattached">
                        <AlertTriangle size={12} /> No shelf — never shown
                    </span>
                ),
        },
        {
            key: 'tagged',
            header: 'Products',
            render: (a) => tagged(a),
        },
        {
            key: 'actions',
            header: 'Actions',
            render: (a) => (
                <div className="admin-attr-actions">
                    <Button
                        variant="outline"
                        size="sm"
                        icon={Edit2}
                        onClick={() => openEdit(a)}
                    >
                        Edit
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        icon={Copy}
                        disabled={copyingId === a.id}
                        onClick={() => duplicate(a)}
                        title="Ask this same question on another shelf"
                    >
                        Copy
                    </Button>
                    <Button
                        variant="danger"
                        size="sm"
                        icon={Trash2}
                        onClick={() => setConfirming(a)}
                    >
                        Delete
                    </Button>
                </div>
            ),
        },
    ];

    const activeType = TYPES.find((t) => t.value === form.input_type);

    return (
        <AdminLayout
            title="Filters"
            subtitle="The questions the sidebar asks, and the answers it offers"
        >
            <Head title="Filters" />

            <div className="admin-page-container">
                <DataTable
                    title="Filters"
                    subtitle={`${counts.total ?? 0} filters · ${counts.values ?? 0} answers · ${counts.unattached ?? 0} on no shelf`}
                    columns={columns}
                    data={attributes}
                    searchable
                    searchValue={search}
                    onSearch={onSearch}
                    searchPlaceholder="Search filters..."
                    headerActions={
                        <Button icon={Plus} onClick={openCreate}>
                            Add filter
                        </Button>
                    }
                    emptyIcon={SlidersHorizontal}
                    emptyTitle="No filters yet"
                    emptyDescription="Add a question the sidebar should ask, so shoppers can narrow a category down."
                />
            </div>

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : 'Add filter'}
                maxWidth="680px"
            >
                <FormInput
                    id="attr_name"
                    name="name"
                    label="Question"
                    required
                    value={form.name}
                    onChange={(e) =>
                        setForm((f) => ({ ...f, name: e.target.value }))
                    }
                    placeholder="Wi-Fi Standard"
                    error={errors.name?.[0] || ''}
                    helperText="What the sidebar calls this group of checkboxes."
                />

                <FormSelect
                    id="attr_type"
                    name="input_type"
                    label="Answer type"
                    required
                    value={form.input_type}
                    onChange={(e) =>
                        setForm((f) => ({ ...f, input_type: e.target.value }))
                    }
                    options={TYPES.map((t) => ({
                        value: t.value,
                        label: t.label,
                    }))}
                    error={errors.input_type?.[0] || ''}
                    helperText={activeType?.hint}
                />

                {isNumber && (
                    <FormInput
                        id="attr_unit"
                        name="unit"
                        label="Unit"
                        value={form.unit}
                        onChange={(e) =>
                            setForm((f) => ({ ...f, unit: e.target.value }))
                        }
                        placeholder="Mbps"
                        error={errors.unit?.[0] || ''}
                        helperText="Shown after the number. Cleared automatically if you switch away from a number."
                    />
                )}

                <CategoryPicker
                    id="attr_categories"
                    label="Shown on these shelves"
                    multiple
                    chips={form.categories}
                    value={form.categories.map((c) => c.id)}
                    onChange={addCategory}
                    onRemove={removeCategory}
                    placeholder="Type to find a category…"
                    helperText="Inherited downward, so attaching to Router covers every router brand under it. A filter on no shelf is never offered to anyone."
                />

                {/* ── the answers ───────────────────────────────────── */}
                <div className="admin-attr-values">
                    <div className="admin-attr-values-head">
                        <h4 className="admin-form-section-title">Answers</h4>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            icon={Plus}
                            onClick={addValue}
                        >
                            Add answer
                        </Button>
                    </div>

                    <p className="admin-form-hint">
                        {isNumber
                            ? 'Each row is one band a shopper ticks. Leave a bound empty for an open end — "Up to 300" or "1801 and above".'
                            : 'Each row is one checkbox. Two products giving the same answer are found together, so keep the wording identical.'}
                    </p>

                    {errors.values?.[0] && (
                        <p className="auth-field-error">{errors.values[0]}</p>
                    )}

                    <div className="admin-attr-value-rows">
                        {form.values.map((value, index) => {
                            const labelError =
                                errors[`values.${index}.label`]?.[0] || '';
                            const rangeError =
                                errors[`values.${index}.range_from`]?.[0] ||
                                errors[`values.${index}.range_to`]?.[0] ||
                                '';

                            return (
                                <div
                                    key={value.key}
                                    className={`admin-attr-value-row${
                                        draggingKey === value.key
                                            ? ' is-dragging'
                                            : ''
                                    }`}
                                    draggable={armedKey === value.key}
                                    onDragStart={() => startDrag(value.key)}
                                    onDragEnter={() => dragOver(index)}
                                    onDragOver={(event) =>
                                        event.preventDefault()
                                    }
                                    onDragEnd={endDrag}
                                    onDrop={endDrag}
                                >
                                    {/*
                                        A button, not decoration: arrow keys
                                        move the row for anyone who cannot
                                        drag, and the label says which row it
                                        belongs to when read aloud.
                                    */}
                                    <button
                                        type="button"
                                        className="admin-attr-value-handle"
                                        aria-label={`Reorder answer ${index + 1}. Use the up and down arrow keys.`}
                                        onMouseDown={() =>
                                            setArmedKey(value.key)
                                        }
                                        onMouseUp={() => setArmedKey(null)}
                                        onKeyDown={(event) =>
                                            onHandleKey(event, index)
                                        }
                                        disabled={form.values.length === 1}
                                    >
                                        <GripVertical size={14} />
                                    </button>

                                    <div className="admin-attr-value-fields">
                                        <FormInput
                                            id={`attr_value_${value.key}`}
                                            name={`value_${value.key}`}
                                            label={
                                                index === 0
                                                    ? 'Label'
                                                    : undefined
                                            }
                                            value={value.label}
                                            onChange={(e) =>
                                                setValue(value.key, {
                                                    label: e.target.value,
                                                })
                                            }
                                            placeholder={
                                                isNumber
                                                    ? '301 to 750 Mbps'
                                                    : 'Wi-Fi 6'
                                            }
                                            error={labelError}
                                        />

                                        {isNumber && (
                                            <>
                                                <FormInput
                                                    id={`attr_from_${value.key}`}
                                                    name={`from_${value.key}`}
                                                    label={
                                                        index === 0
                                                            ? 'From'
                                                            : undefined
                                                    }
                                                    type="number"
                                                    value={value.range_from}
                                                    onChange={(e) =>
                                                        setValue(value.key, {
                                                            range_from:
                                                                e.target.value,
                                                        })
                                                    }
                                                    placeholder="301"
                                                    error={rangeError}
                                                />
                                                <FormInput
                                                    id={`attr_to_${value.key}`}
                                                    name={`to_${value.key}`}
                                                    label={
                                                        index === 0
                                                            ? 'To'
                                                            : undefined
                                                    }
                                                    type="number"
                                                    value={value.range_to}
                                                    onChange={(e) =>
                                                        setValue(value.key, {
                                                            range_to:
                                                                e.target.value,
                                                        })
                                                    }
                                                    placeholder="750"
                                                />
                                            </>
                                        )}
                                    </div>

                                    {/*
                                        An answer products already carry cannot
                                        be removed — the join cascades, so it
                                        would untag them without a word. The
                                        count is here so that is visible before
                                        the save is refused.
                                    */}
                                    {value.products_count > 0 ? (
                                        <span
                                            className="admin-attr-value-locked"
                                            title={`${value.products_count} product(s) answer this way. Retag them before removing it.`}
                                        >
                                            {value.products_count} tagged
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            className="admin-attr-value-remove"
                                            onClick={() =>
                                                removeValue(value.key)
                                            }
                                            aria-label={`Remove answer ${index + 1}`}
                                            disabled={form.values.length === 1}
                                        >
                                            <X size={14} />
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="admin-modal-actions">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => setModalOpen(false)}
                        disabled={saving}
                    >
                        Cancel
                    </Button>
                    <Button type="button" onClick={save} disabled={saving}>
                        {saving
                            ? 'Saving…'
                            : editing
                              ? 'Save filter'
                              : 'Create filter'}
                    </Button>
                </div>
            </Modal>

            <ConfirmDialog
                isOpen={Boolean(confirming)}
                onCancel={() => setConfirming(null)}
                onConfirm={remove}
                title={`Delete ${confirming?.name ?? 'filter'}?`}
                confirmLabel="Delete"
                variant="danger"
                message={
                    confirming && tagged(confirming) > 0
                        ? `${tagged(confirming)} product(s) answer this filter. Deleting it would untag every one of them, so it will be refused — unlink it from its shelves instead.`
                        : 'Its answers go with it. Nothing is tagged with them, so no product changes.'
                }
            />
        </AdminLayout>
    );
}
