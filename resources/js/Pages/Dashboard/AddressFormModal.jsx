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
                addressForm.values.id
                    ? 'Edit Delivery Address'
                    : 'Add Delivery Address'
            }
            maxWidth="480px"
        >
            <form onSubmit={handleAddressSubmit} noValidate>
                <div className="auth-form-group">
                    <label className="auth-label">Division</label>
                    <select
                        value={addressForm.values.division}
                        name="division"
                        onBlur={addressForm.handleBlur}
                        onChange={addressForm.handleChange}
                        className={`auth-text-input dash-input-pad${addressForm.touched.division && addressForm.errors.division ? ' input-error' : ''}`}
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

                    {addressForm.touched.division &&
                        addressForm.errors.division && (
                            <span className="auth-field-error">
                                {addressForm.errors.division}
                            </span>
                        )}
                </div>

                <div className="auth-form-group">
                    <label className="auth-label">District</label>
                    <input
                        type="text"
                        value={addressForm.values.district}
                        name="district"
                        onBlur={addressForm.handleBlur}
                        onChange={addressForm.handleChange}
                        placeholder="e.g. Dhaka"
                        className={`auth-text-input dash-input-pad${addressForm.touched.district && addressForm.errors.district ? ' input-error' : ''}`}
                    />

                    {addressForm.touched.district &&
                        addressForm.errors.district && (
                            <span className="auth-field-error">
                                {addressForm.errors.district}
                            </span>
                        )}
                </div>

                <div className="auth-form-group">
                    <label className="auth-label">City / Thana / Area</label>
                    <input
                        type="text"
                        value={addressForm.values.city}
                        name="city"
                        onBlur={addressForm.handleBlur}
                        onChange={addressForm.handleChange}
                        placeholder="e.g. Dhanmondi, Gulshan, Agrabad"
                        className={`auth-text-input dash-input-pad${addressForm.touched.city && addressForm.errors.city ? ' input-error' : ''}`}
                    />

                    {addressForm.touched.city && addressForm.errors.city && (
                        <span className="auth-field-error">
                            {addressForm.errors.city}
                        </span>
                    )}
                </div>

                <div className="auth-form-group">
                    <label className="auth-label">Full Street Address</label>
                    <textarea
                        value={addressForm.values.address}
                        name="address"
                        onBlur={addressForm.handleBlur}
                        onChange={addressForm.handleChange}
                        placeholder="House, Road, Block, Flat Number"
                        className={`auth-text-input dash-textarea-custom${addressForm.touched.address && addressForm.errors.address ? ' input-error' : ''}`}
                    />

                    {addressForm.touched.address &&
                        addressForm.errors.address && (
                            <span className="auth-field-error">
                                {addressForm.errors.address}
                            </span>
                        )}
                </div>

                {/*
                 * Asked, not worked out. Delivery is priced by zone, and
                 * neither field above settles it: the district is typed by
                 * hand, and the Dhaka division reaches Gazipur and Tangail.
                 * Kept here so an address saved in the book arrives at
                 * checkout already knowing what it costs to deliver to.
                 */}
                <div className="auth-form-group">
                    <label className="auth-label">Delivery Area</label>
                    <select
                        value={addressForm.values.delivery_zone}
                        name="delivery_zone"
                        onBlur={addressForm.handleBlur}
                        onChange={addressForm.handleChange}
                        className={`auth-text-input dash-input-pad${addressForm.touched.delivery_zone && addressForm.errors.delivery_zone ? ' input-error' : ''}`}
                    >
                        <option value="">Choose an area…</option>
                        <option value="inside_dhaka">Inside Dhaka</option>
                        <option value="outside_dhaka">Outside Dhaka</option>
                    </select>

                    {addressForm.touched.delivery_zone &&
                        addressForm.errors.delivery_zone && (
                            <span className="auth-field-error">
                                {addressForm.errors.delivery_zone}
                            </span>
                        )}
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
                        disabled={addressForm.isSubmitting}
                    >
                        Save Address
                    </button>
                </div>
            </form>
        </Modal>
    );
}
