import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useFormik } from 'formik';
import AdminLayout from '@/Layouts/AdminLayout';
import { Truck, Plus, Edit2, Trash2, MapPin } from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Checkbox from '@/Components/Checkbox';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import Modal from '@/Components/Modal';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { adminCourierSchema } from '@/validations';
import './Couriers.css';

const empty = {
    name: '',
    driver: 'manual',
    is_sandbox: false,
    tracking_url_template: '',
    phone: '',
    note: '',
    credentials: {},
};

/**
 * The delivery companies the shop hands parcels to.
 *
 * Seeded with the carriers most Bangladeshi shops use, and editable, because
 * carriers change their tracking URLs and correcting one should not need a
 * deploy.
 */
export default function AdminCouriers({
    couriers = [],
    placeholder = '{tracking}',
    drivers = [],
    zones = {},
}) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [mapping, setMapping] = useState(null);

    /*
     * Which carriers book against their own area ids.
     *
     * Read off what each driver asks for rather than listed here: a driver
     * that wants a default city, zone or area is a driver whose bookings can
     * land in the wrong district without a mapping. "manual" is not one — a
     * courier dispatched by hand has no ids to map — and neither is Steadfast,
     * which takes the written address.
     */
    const mappable = new Set(
        drivers
            .filter((d) =>
                (d.fields ?? []).some((f) =>
                    [
                        'default_city_id',
                        'default_zone_id',
                        'default_area_id',
                    ].includes(f.name),
                ),
            )
            .map((d) => d.key),
    );

    const formik = useFormik({
        initialValues: empty,
        validationSchema: adminCourierSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                if (editing) {
                    await adminService.updateCourier(editing.id, values);
                    toast.success(`"${values.name}" updated.`);
                } else {
                    await adminService.createCourier(values);
                    toast.success(`"${values.name}" added.`);
                }

                setModalOpen(false);
                setEditing(null);
                resetForm({ values: empty });
                router.reload({ only: ['couriers'] });
            } catch (err) {
                toast.error(err?.message || 'Could not save that courier.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const activeDriver = drivers.find((d) => d.key === formik.values.driver);
    const hasCredentials = Boolean(editing?.has_credentials);

    const openCreate = () => {
        setEditing(null);
        formik.resetForm({ values: empty });
        setModalOpen(true);
    };

    const openEdit = (courier) => {
        setEditing(courier);
        formik.resetForm({
            values: {
                name: courier.name || '',
                driver: courier.driver || 'manual',
                is_sandbox: Boolean(courier.is_sandbox),
                tracking_url_template: courier.tracking_url_template || '',
                phone: courier.phone || '',
                note: courier.note || '',
                // Never sent back down, so the boxes start empty. Leaving one
                // blank keeps whatever is already saved.
                credentials: {},
            },
        });
        setModalOpen(true);
    };

    const remove = async (courier) => {
        const warning = courier.orders_count
            ? `"${courier.name}" has carried ${courier.orders_count} order(s). It will be hidden rather than deleted so those orders can still say who took them. Continue?`
            : `Remove "${courier.name}"?`;

        if (!confirm(warning)) return;

        try {
            const res = await adminService.deleteCourier(courier.id);
            toast.success(
                res?.name ? `"${res.name}" hidden.` : 'Courier removed.',
            );
            router.reload({ only: ['couriers'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that courier.');
        }
    };

    const columns = [
        {
            key: 'name',
            header: 'Courier',
            render: (c) => (
                <div>
                    <div className="admin-stock-product-name">
                        {c.name}
                        {!c.is_active && (
                            <span className="admin-supplier-retired">
                                Hidden
                            </span>
                        )}
                    </div>
                    {(c.phone || c.note) && (
                        <div className="admin-field-hint">
                            {[c.phone, c.note].filter(Boolean).join(' · ')}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'tracking',
            header: 'Tracking link',
            render: (c) =>
                c.tracking_url_template ? (
                    <code className="admin-field-hint">
                        {c.tracking_url_template}
                    </code>
                ) : (
                    // Not a gap to be filled in: several carriers genuinely
                    // have no per-parcel page, and the number is still recorded.
                    <span className="admin-field-hint">
                        No public lookup — number recorded only
                    </span>
                ),
        },
        {
            key: 'booking',
            header: 'Booking',
            render: (c) =>
                c.can_book ? (
                    <span className="admin-badge-stock admin-badge-stock-ok">
                        Books via API
                    </span>
                ) : c.driver && c.driver !== 'manual' ? (
                    // A driver exists but there are no keys, so it still
                    // dispatches by hand. Worth saying, because it looks
                    // integrated from the outside.
                    <span className="admin-field-hint">
                        API available — add credentials
                    </span>
                ) : (
                    <span className="admin-field-hint">Number typed in</span>
                ),
        },
        {
            key: 'orders',
            header: 'Parcels',
            render: (c) => c.orders_count ?? 0,
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (c) => (
                <div className="admin-input-row-flex admin-order-actions">
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Edit courier"
                        onClick={() => openEdit(c)}
                    >
                        <Edit2 size={14} />
                    </button>
                    {mappable.has(c.driver) && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Delivery areas"
                            onClick={() => setMapping(c)}
                        >
                            <MapPin size={14} />
                            {(zones[c.id]?.length ?? 0) > 0 && (
                                <span className="courier-zone-count">
                                    {zones[c.id].length}
                                </span>
                            )}
                        </button>
                    )}
                    <button
                        type="button"
                        className="admin-table-icon-btn"
                        title="Remove courier"
                        onClick={() => remove(c)}
                    >
                        <Trash2 size={14} />
                    </button>
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Couriers"
            subtitle="Who carries the parcels, and where customers track them"
        >
            <Head title="Couriers" />

            <DataTable
                columns={columns}
                data={couriers}
                title="Couriers"
                subtitle="Offered when dispatching an order"
                headerActions={
                    <Button icon={Plus} onClick={openCreate}>
                        Add courier
                    </Button>
                }
                emptyTitle="No couriers yet"
                emptyDescription="Add the delivery companies the shop hands parcels to."
                emptyIcon={Truck}
                emptyActionText="Add courier"
                onEmptyAction={openCreate}
            />

            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : 'Add courier'}
                maxWidth="620px"
                footer={
                    <div className="admin-input-row-flex admin-modal-actions">
                        <Button
                            variant="secondary"
                            onClick={() => setModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={formik.handleSubmit}
                            disabled={formik.isSubmitting}
                        >
                            {formik.isSubmitting
                                ? 'Saving…'
                                : editing
                                  ? 'Save changes'
                                  : 'Add courier'}
                        </Button>
                    </div>
                }
            >
                <form onSubmit={formik.handleSubmit} noValidate>
                    <FormInput
                        label="Name"
                        name="name"
                        formik={formik}
                        required
                        placeholder="Pathao Courier"
                    />

                    <FormSelect
                        label="How parcels are booked"
                        name="driver"
                        formik={formik}
                        options={drivers.map((d) => ({
                            value: d.key,
                            label: d.label,
                        }))}
                    />

                    {activeDriver?.fields?.length > 0 && (
                        <div className="courier-credentials">
                            <strong>{activeDriver.label} credentials</strong>
                            <span className="admin-field-hint">
                                From your merchant panel. Stored encrypted, and
                                never shown again — leave a box blank to keep
                                what is already saved.
                                {hasCredentials && ' Credentials are saved.'}
                            </span>

                            {activeDriver.fields.map((field) => (
                                <FormInput
                                    key={field.name}
                                    label={field.label}
                                    name={`credentials.${field.name}`}
                                    type={field.secret ? 'password' : 'text'}
                                    formik={formik}
                                    placeholder={
                                        hasCredentials
                                            ? '•••••• (unchanged)'
                                            : ''
                                    }
                                />
                            ))}
                            {activeDriver.fields
                                .filter((f) => f.hint)
                                .map((f) => (
                                    <span
                                        key={`${f.name}-hint`}
                                        className="admin-field-hint"
                                    >
                                        {f.label}: {f.hint}
                                    </span>
                                ))}

                            <Checkbox
                                name="is_sandbox"
                                label="Use the courier's sandbox (test bookings, no real parcels)"
                                checked={formik.values.is_sandbox}
                                onChange={formik.handleChange}
                            />
                        </div>
                    )}

                    <FormInput
                        label="Tracking link"
                        name="tracking_url_template"
                        formik={formik}
                        placeholder={`https://example.com/track/${placeholder}`}
                    />
                    <span className="admin-field-hint">
                        Put <code>{placeholder}</code> where the consignment
                        number goes. Leave blank if the courier has no public
                        lookup — the number is still recorded and printed. Worth
                        checking against the merchant panel, since carriers
                        change these.
                    </span>

                    <div className="admin-grid-equal-2col">
                        <FormInput
                            label="Phone"
                            name="phone"
                            formik={formik}
                            placeholder="09678100800"
                        />
                        <FormInput
                            label="Note"
                            name="note"
                            formik={formik}
                            placeholder="Optional"
                        />
                    </div>
                </form>
            </Modal>

            <ZoneMappingModal
                courier={mapping}
                rows={mapping ? (zones[mapping.id] ?? []) : []}
                onClose={() => setMapping(null)}
            />
        </AdminLayout>
    );
}

/**
 * Which of a courier's own delivery areas an address belongs to.
 *
 * Pathao and RedX book against numeric ids from their own lists rather than
 * against a written address. With nothing mapped, every parcel goes out on the
 * one default saved with the credentials — right for the shop's own district
 * and wrong for the other sixty-three, and wrong quietly: the booking succeeds
 * and a rider turns up in the wrong place.
 *
 * The ids have to be copied from the courier's own panel; there is no way to
 * guess them, and inventing a lookup that guessed would be worse than asking.
 */
function ZoneMappingModal({ courier, rows, onClose }) {
    const [city, setCity] = useState('');
    const [zone, setZone] = useState('');
    const [cityId, setCityId] = useState('');
    const [zoneId, setZoneId] = useState('');
    const [areaId, setAreaId] = useState('');
    const [saving, setSaving] = useState(false);

    // RedX books on a single area id; Pathao wants a city and a zone.
    const usesArea = courier?.driver === 'redx';

    const reset = () => {
        setCity('');
        setZone('');
        setCityId('');
        setZoneId('');
        setAreaId('');
    };

    const save = async () => {
        setSaving(true);

        try {
            const res = await adminService.mapCourierZone(courier.id, {
                city,
                zone: zone || null,
                city_id: cityId || null,
                zone_id: zoneId || null,
                area_id: areaId || null,
            });
            toast.success(res?.message || 'Area mapped.');
            reset();
            router.reload({ only: ['zones'] });
        } catch (err) {
            toast.error(err?.message || 'Could not save that mapping.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (row) => {
        try {
            await adminService.unmapCourierZone(courier.id, row.id);
            toast.success('Mapping removed.');
            router.reload({ only: ['zones'] });
        } catch (err) {
            toast.error(err?.message || 'Could not remove that mapping.');
        }
    };

    return (
        <Modal
            isOpen={Boolean(courier)}
            onClose={onClose}
            title={`${courier?.name ?? ''} delivery areas`}
            maxWidth="640px"
            footer={
                <Button variant="secondary" onClick={onClose}>
                    Done
                </Button>
            }
        >
            <p className="admin-field-hint" style={{ marginBottom: 18 }}>
                Match the city and thana your customers type at checkout to the
                ids in {courier?.name}&rsquo;s own area list. Anything not
                mapped falls back to the default saved with the credentials.
            </p>

            <div className="courier-zone-form">
                <FormInput
                    label="City / District"
                    name="zone_city"
                    required
                    value={city}
                    onChange={(e) => setCity(e.target.value)}
                    placeholder="e.g. Chattogram"
                />
                <FormInput
                    label="Zone / Thana"
                    name="zone_zone"
                    value={zone}
                    onChange={(e) => setZone(e.target.value)}
                    placeholder="Leave blank for the whole district"
                />

                {usesArea ? (
                    <FormInput
                        label="Area ID"
                        name="zone_area_id"
                        required
                        value={areaId}
                        onChange={(e) => setAreaId(e.target.value)}
                    />
                ) : (
                    <>
                        <FormInput
                            label="City ID"
                            name="zone_city_id"
                            value={cityId}
                            onChange={(e) => setCityId(e.target.value)}
                        />
                        <FormInput
                            label="Zone ID"
                            name="zone_zone_id"
                            value={zoneId}
                            onChange={(e) => setZoneId(e.target.value)}
                        />
                    </>
                )}
            </div>

            <Button
                variant="primary"
                size="sm"
                icon={Plus}
                onClick={save}
                loading={saving}
                disabled={
                    !city.trim() || (usesArea ? !areaId : !cityId && !zoneId)
                }
            >
                Add mapping
            </Button>

            {rows.length > 0 && (
                <table className="courier-zone-table">
                    <thead>
                        <tr>
                            <th>Where</th>
                            <th>{usesArea ? 'Area' : 'City / Zone'}</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td>
                                    {row.zone
                                        ? `${row.zone}, ${row.city}`
                                        : row.city}
                                </td>
                                <td>
                                    <code>
                                        {usesArea
                                            ? row.area_id
                                            : [row.city_id, row.zone_id]
                                                  .filter(Boolean)
                                                  .join(' / ')}
                                    </code>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        className="admin-table-icon-btn"
                                        title="Remove mapping"
                                        onClick={() => remove(row)}
                                    >
                                        <Trash2 size={13} />
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Modal>
    );
}
