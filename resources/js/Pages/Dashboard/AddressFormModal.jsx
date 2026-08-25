import React from 'react';
import { Modal } from '@/Components/Modal';

export default function AddressFormModal({
    showAddressModal,
    setShowAddressModal,
    addressForm,
    handleAddressSubmit,
}) {
    return (
        <Modal
            isOpen={showAddressModal}
            onClose={() => setShowAddressModal(false)}
            title={
                addressForm.data.id
                    ? 'Edit Delivery Address'
                    : 'Add Delivery Address'
            }
            maxWidth="480px"
        >
            <form onSubmit={handleAddressSubmit}>
                <div className="auth-form-group">
                    <label className="auth-label">Division</label>
                    <select
                        value={addressForm.data.division}
                        onChange={(e) =>
                            addressForm.setData('division', e.target.value)
                        }
                        className="auth-text-input dash-input-pad"
                    >
                        <option value="Dhaka">Dhaka</option>
                        <option value="Chattogram">Chattogram</option>
                        <option value="Rajshahi">Rajshahi</option>
                        <option value="Khulna">Khulna</option>
                        <option value="Sylhet">Sylhet</option>
                        <option value="Barishal">Barishal</option>
                        <option value="Rangpur">Rangpur</option>
                        <option value="Mymensingh">Mymensingh</option>
                    </select>
                </div>

                <div className="auth-form-group">
                    <label className="auth-label">District</label>
                    <input
                        type="text"
                        value={addressForm.data.district}
                        onChange={(e) =>
                            addressForm.setData('district', e.target.value)
                        }
                        placeholder="e.g. Dhaka"
                        className="auth-text-input dash-input-pad"
                    />
                </div>

                <div className="auth-form-group">
                    <label className="auth-label">City / Thana / Area</label>
                    <input
                        type="text"
                        value={addressForm.data.city}
                        onChange={(e) =>
                            addressForm.setData('city', e.target.value)
                        }
                        placeholder="e.g. Dhanmondi, Gulshan, Agrabad"
                        className="auth-text-input dash-input-pad"
                    />
                </div>

                <div className="auth-form-group">
                    <label className="auth-label">Full Street Address</label>
                    <textarea
                        value={addressForm.data.address}
                        onChange={(e) =>
                            addressForm.setData('address', e.target.value)
                        }
                        placeholder="House, Road, Block, Flat Number"
                        className="auth-text-input dash-textarea-custom"
                    />
                </div>

                <div className="dash-modal-btn-row">
                    <button
                        type="button"
                        className="btn btn-outline"
                        onClick={() => setShowAddressModal(false)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={addressForm.processing}
                    >
                        Save Address
                    </button>
                </div>
            </form>
        </Modal>
    );
}
