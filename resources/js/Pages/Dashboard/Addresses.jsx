import React, { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { MapPin, Plus, Trash2 } from 'lucide-react';
import AccountLayout from './AccountLayout';
import AddressFormModal from './AddressFormModal';
import { API_ENDPOINTS } from '@/constants/endpoints';

export default function Addresses({
    user,
    navCounts,
    techPoints,
    addresses = [],
}) {
    const [showAddressModal, setShowAddressModal] = useState(false);

    const addressForm = useForm({
        name: user?.name || '',
        phone: user?.phone || '',
        division: 'Dhaka',
        district: 'Dhaka',
        city: '',
        address: '',
        is_default: true,
    });

    const handleAddressSubmit = (e) => {
        e.preventDefault();
        addressForm.post(API_ENDPOINTS.ACCOUNT.ADDRESS, {
            preserveScroll: true,
            onSuccess: () => {
                setShowAddressModal(false);
                addressForm.reset();
            },
        });
    };

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
                    <div className="addresses-grid">
                        {addresses.map((addr) => (
                            <div
                                key={addr.id}
                                className={`address-item-card ${addr.is_default ? 'is-default-address' : ''}`}
                            >
                                {addr.is_default && (
                                    <span className="default-addr-badge">
                                        DEFAULT ADDRESS
                                    </span>
                                )}
                                <h4>
                                    {addr.city}, {addr.district}
                                </h4>
                                <p>{addr.address}</p>
                                <span className="dash-address-meta">
                                    Division: <strong>{addr.division}</strong>
                                </span>
                                <div className="dash-address-actions">
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
                handleAddressSubmit={handleAddressSubmit}
            />
        </AccountLayout>
    );
}
