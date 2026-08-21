import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import { Button, DataTable, Modal, FormSelect, toast } from '../../Components';
import { API_ENDPOINTS } from '../../constants/endpoints';
import axiosInstance from '../../services/axiosInstance';
import { ShieldCheck, Wrench, Edit3 } from 'lucide-react';

const STATUS_OPTIONS = [
    { value: 'received', label: 'Received at Lab' },
    { value: 'diagnosing', label: 'Under Diagnostic Bench Test' },
    { value: 'repairing', label: 'Repairing / Sent to OEM Vendor' },
    {
        value: 'ready_for_pickup',
        label: 'Ready for Customer Pickup / Dispatch',
    },
    { value: 'completed', label: 'Service Completed' },
    { value: 'rejected', label: 'Claim Rejected (Physical Damage / Void)' },
];

export default function AdminWarranty({ claims = [] }) {
    const [selectedClaim, setSelectedClaim] = useState(null);
    const [updatingStatus, setUpdatingStatus] = useState('');
    const [diagnosticNotes, setDiagnosticNotes] = useState('');
    const [isSaving, setIsSaving] = useState(false);

    const handleOpenEdit = (claim) => {
        setSelectedClaim(claim);
        setUpdatingStatus(claim.status);
        setDiagnosticNotes(claim.diagnostic_notes || '');
    };

    const handleSaveStatus = async () => {
        if (!selectedClaim) return;
        setIsSaving(true);
        try {
            await axiosInstance.patch(
                API_ENDPOINTS.ADMIN.WARRANTY_STATUS(selectedClaim.id),
                {
                    status: updatingStatus,
                    diagnostic_notes: diagnosticNotes,
                },
            );
            toast.success(
                `RMA #${selectedClaim.claim_number} updated to ${updatingStatus.toUpperCase()}!`,
            );
            setSelectedClaim(null);
            router.reload({ only: ['claims'] });
        } catch (err) {
            toast.error(err?.message || 'Failed to update claim status.');
        } finally {
            setIsSaving(false);
        }
    };

    const columns = [
        {
            key: 'claim_number',
            header: 'RMA Code',
            render: (claim) => (
                <strong className="text-primary font-heading tracking-wide">
                    {claim.claim_number}
                </strong>
            ),
        },
        {
            key: 'product',
            header: 'Product & S/N',
            render: (claim) => (
                <div>
                    <strong className="admin-table-title-bold">
                        {claim.product_name}
                    </strong>
                    <span className="admin-table-mono-sub">
                        S/N: {claim.serial_number}
                    </span>
                </div>
            ),
        },
        {
            key: 'customer',
            header: 'Customer Details',
            render: (claim) => (
                <div>
                    <div className="font-semibold text-sm">
                        {claim.customer_name}
                    </div>
                    <small className="text-muted text-xs">
                        {claim.customer_phone}
                    </small>
                </div>
            ),
        },
        {
            key: 'issue',
            header: 'Issue Category',
            render: (claim) => (
                <span className="badge badge-info">{claim.issue_type}</span>
            ),
        },
        {
            key: 'status',
            header: 'Service Status',
            render: (claim) => (
                <span
                    className={`status-pill ${
                        claim.status === 'completed'
                            ? 'active'
                            : claim.status === 'rejected'
                              ? 'inactive'
                              : 'pending'
                    }`}
                >
                    {claim.status.replace(/_/g, ' ').toUpperCase()}
                </span>
            ),
        },
        {
            key: 'created_at',
            header: 'Intake Date',
            render: (claim) => (
                <small className="text-muted">
                    {new Date(claim.created_at).toLocaleDateString()}
                </small>
            ),
        },
        {
            key: 'actions',
            header: 'Action',
            align: 'right',
            render: (claim) => (
                <Button
                    variant="secondary"
                    size="sm"
                    icon={Edit3}
                    onClick={() => handleOpenEdit(claim)}
                >
                    Update
                </Button>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Warranty & RMA Service Center"
            subtitle="Manage hardware repair tickets, OEM warranty replacements, and diagnostic logs"
        >
            <Head title="Admin Warranty & RMA — Robin IT" />

            <div className="admin-page-container">
                {/* Standard Reusable DataTable Component */}
                <DataTable
                    title="Active Warranty Claims & RMA Tickets"
                    subtitle="Track diagnostic bench tests, vendor RMA transfers, and customer pickup handovers."
                    columns={columns}
                    data={claims}
                    searchable
                    searchPlaceholder="Search by RMA code, S/N, customer, or product..."
                    emptyTitle="No Warranty Claims Found"
                    emptyDescription="There are currently no active repair or RMA tickets registered."
                    emptyIcon={ShieldCheck}
                />

                {/* Standard Reusable Modal Component */}
                <Modal
                    isOpen={Boolean(selectedClaim)}
                    onClose={() => setSelectedClaim(null)}
                    title={
                        selectedClaim
                            ? `Update RMA #${selectedClaim.claim_number}`
                            : ''
                    }
                    maxWidth="560px"
                >
                    {selectedClaim && (
                        <div>
                            <div className="admin-summary-box">
                                <div className="admin-summary-box-row">
                                    <strong>Product:</strong>{' '}
                                    {selectedClaim.product_name}
                                </div>
                                <div className="admin-summary-box-row">
                                    <strong>Serial Number:</strong>{' '}
                                    <code className="text-primary">
                                        {selectedClaim.serial_number}
                                    </code>
                                </div>
                                <div className="admin-summary-box-row text-muted">
                                    <strong>Reported Defect:</strong>{' '}
                                    {selectedClaim.issue_description}
                                </div>
                            </div>

                            <div className="admin-form-stack">
                                <FormSelect
                                    label="Service / Repair Stage"
                                    value={updatingStatus}
                                    onChange={(e) =>
                                        setUpdatingStatus(e.target.value)
                                    }
                                    options={STATUS_OPTIONS}
                                />

                                <div>
                                    <label className="admin-form-field-label">
                                        Technician Diagnostic Log / Repair Notes
                                    </label>
                                    <textarea
                                        value={diagnosticNotes}
                                        onChange={(e) =>
                                            setDiagnosticNotes(e.target.value)
                                        }
                                        rows={4}
                                        className="form-input w-full"
                                        placeholder="e.g. Diagnostic complete. Replaced blown VRM capacitor on power stage. Unit passed 24h FurMark burn-in test at 68°C."
                                    />
                                </div>

                                <div className="admin-modal-action-row">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => setSelectedClaim(null)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="primary"
                                        onClick={handleSaveStatus}
                                        loading={isSaving}
                                    >
                                        Save RMA Status
                                    </Button>
                                </div>
                            </div>
                        </div>
                    )}
                </Modal>
            </div>
        </AdminLayout>
    );
}
