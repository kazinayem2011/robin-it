import React, { useState } from 'react';
import { useFormik } from 'formik';
import { router } from '@inertiajs/react';
import { MapPin, Plus, Trash2 } from 'lucide-react';
import AccountLayout from './AccountLayout';
import AddressFormModal from './AddressFormModal';
import { deliveryAddressSchema } from '@/validations';
import { API_ENDPOINTS } from '@/constants/endpoints';
import { formatBdPhone } from '@/utils/formatters';

export default function Addresses({
    user,
    navCounts,
    techPoints,
    addresses = [],
}) {
    const [showAddressModal, setShowAddressModal] = useState(false);

    /*
     * Checked here before it is sent. A courier cannot deliver to an address
     * with no street line or an unreachable number, and the round trip to be
     * told so is wasted on a mobile connection.
     */
    const addressForm = useFormik({
        initialValues: {
            name: user?.name || '',
            phone: user?.phone || '',
            division: 'Dhaka',
            district: 'Dhaka',
            city: '',
            address: '',
            is_default: true,
        },
        validationSchema: deliveryAddressSchema,
        onSubmit: (values, { setSubmitting, setErrors, resetForm }) => {
            router.post(API_ENDPOINTS.ACCOUNT.ADDRESS, values, {
                preserveScroll: true,
                onSuccess: () => {
                    setShowAddressModal(false);
                    resetForm();
                },
                onError: (errors) => setErrors(errors),
                onFinish: () => setSubmitting(false),
            });
        },
    });

    const handleDeleteAddress = (id) => {
        if (confirm('Are you sure you want to remove this delivery address?')) {
            router.delete(API_ENDPOINTS.ACCOUNT.ADDRESS_ITEM(id), {
                preserveScroll: true,
            });
        }
    };

    return (
        <AccountLayout
            title="Delivery Addresses"
            active="addresses"
            user={user}
            navCounts={navCounts}
            techPoints={techPoints}
        >
            <div>
                <div className="dash-tab-header">
                    <div>
                        <h2>Delivery Addresses (Bangladesh)</h2>
                        <p>
                            Manage your nationwide delivery addresses for
                            seamless 1-click checkout.
                        </p>
                    </div>
                    <button
                        className="btn btn-primary btn-sm"
                        onClick={() => {
                            addressForm.reset();
                            setShowAddressModal(true);
                        }}
                    >
                        <Plus size={15} /> Add New Address
                    </button>
                </div>

                {addresses.length === 0 ? (
                    <div className="dash-empty-box">
                        <MapPin size={40} className="dash-empty-icon" />
                        <p className="dash-empty-text">
                            No saved addresses yet.
                        </p>
                        <button
                            className="btn btn-outline btn-sm mt-3"
                            onClick={() => setShowAddressModal(true)}
                        >
                            Add Your First Address
                        </button>
                    </div>
                ) : (
                    /*
                     * Built on the same three-part card as an order — tinted
                     * header carrying the label and a badge, plain body, tinted
                     * footer holding the action. Two different card shapes in
                     * one account area read as two different products.
                     */
                    <div className="addresses-list-wrapper">
                        {addresses.map((addr) => (
                            <div key={addr.id} className="address-history-card">
                                <div className="address-card-header">
                                    <div>
                                        <span className="dash-address-label">
                                            Deliver to:{' '}
                                        </span>
                                        <strong className="dash-address-name">
                                            {addr.name || user?.name}
                                        </strong>
                                        {/* An address saved before the phone
                                            field was required has none, and
                                            formatBdPhone answers "—" for that.
                                            Better to show nothing than a dash
                                            standing in for a number. */}
                                        {addr.phone && (
                                            <>
                                                <span className="dash-order-divider">
                                                    |
                                                </span>
                                                <span className="dash-address-phone">
                                                    {formatBdPhone(addr.phone)}
                                                </span>
                                            </>
                                        )}
                                    </div>
                                    {addr.is_default && (
                                        <span className="address-default-badge">
                                            Default
                                        </span>
                                    )}
                                </div>

                                <div className="address-card-body">
                                    <MapPin size={15} />
                                    <div>
                                        <p className="dash-address-street">
                                            {addr.address}
                                        </p>
                                        <p className="dash-address-region">
                                            {/* Deduplicated: for the
                                                capitals the district and the
                                                division carry the same name,
                                                which read as "Dhaka, Dhaka". */}
                                            {[
                                                ...new Set(
                                                    [
                                                        addr.city,
                                                        addr.district,
                                                        addr.division,
                                                    ]
                                                        .filter(Boolean)
                                                        .map((part) =>
                                                            part.trim(),
                                                        ),
                                                ),
                                            ].join(', ')}
                                        </p>
                                    </div>
                                </div>

                                <div className="address-card-footer">
                                    <span className="dash-address-hint">
                                        {addr.is_default
                                            ? 'Used for new orders'
                                            : 'Saved address'}
                                    </span>
                                    <button
                                        className="btn btn-outline btn-sm dash-address-remove-btn"
                                        onClick={() =>
                                            handleDeleteAddress(addr.id)
                                        }
                                    >
                                        <Trash2 size={13} /> Remove
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <AddressFormModal
                showAddressModal={showAddressModal}
                setShowAddressModal={setShowAddressModal}
                addressForm={addressForm}
                handleAddressSubmit={addressForm.handleSubmit}
            />
        </AccountLayout>
    );
}
