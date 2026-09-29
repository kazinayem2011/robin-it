import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import Button from '../../Components/Button';
import DataTable from '../../Components/DataTable';
import Select from '../../Components/Select';
import Modal from '../../Components/Modal';
import { toast } from '../../Components/Toast';
import { API_ENDPOINTS } from '../../constants/endpoints';
import axiosInstance from '../../services/axiosInstance';
import { ShieldCheck, Edit3, RefreshCw } from 'lucide-react';

// What the shop knows about the unit, as a coloured pill.
const TONE = { ok: 'active', warn: 'pending', bad: 'inactive' };

function CheckTag({ check }) {
    if (!check) return null;

    return (
        <span
            className={`status-pill admin-claim-check ${TONE[check.tone] ?? 'pending'}`}
        >
            {check.label}
        </span>
    );
}

export default function AdminWarranty({ claims = [], statusLabels = {} }) {
    const [selectedClaim, setSelectedClaim] = useState(null);
    const [updatingStatus, setUpdatingStatus] = useState('');
    const [diagnosticNotes, setDiagnosticNotes] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [replacementId, setReplacementId] = useState('');
    const [isReplacing, setIsReplacing] = useState(false);

    const label = (status) => statusLabels[status] ?? status;

    const handleOpenEdit = (claim) => {
        setSelectedClaim(claim);
        setUpdatingStatus(claim.status);
        setDiagnosticNotes(claim.diagnostic_notes || '');
        setReplacementId('');
    };

    const handleSaveStatus = async () => {
        if (!selectedClaim) return;
        setIsSaving(true);
        try {
            const res = await axiosInstance.patch(
                API_ENDPOINTS.ADMIN.WARRANTY_STATUS(selectedClaim.id),
                {
                    status: updatingStatus,
                    diagnostic_notes: diagnosticNotes,
                },
            );
            toast.success(
                res?.message ||
                    `${selectedClaim.claim_number} is now ${label(updatingStatus)}.`,
            );
            setSelectedClaim(null);
            router.reload({ only: ['claims'] });
        } catch (err) {
            toast.error(err?.message || 'Failed to update claim status.');
        } finally {
            setIsSaving(false);
        }
    };

    const handleReplace = async () => {
        if (!selectedClaim || !replacementId) return;
        setIsReplacing(true);
        try {
            const res = await axiosInstance.post(
                API_ENDPOINTS.ADMIN.WARRANTY_REPLACE(selectedClaim.id),
                { serial_id: Number(replacementId) },
            );
            toast.success(res?.message || 'Replacement recorded.');
            setSelectedClaim(null);
            router.reload({ only: ['claims'] });
        } catch (err) {
            toast.error(err?.message || 'Could not record the replacement.');
        } finally {
            setIsReplacing(false);
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
            key: 'check',
            header: 'Our record',
            render: (claim) => (
                <div>
                    <CheckTag check={claim.check} />
                    {claim.check?.order_number && (
                        <small className="text-muted text-xs">
                            {' '}
                            {claim.check.order_number}
                        </small>
                    )}
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
            header: 'Stage',
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
                    {label(claim.status)}
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

    const stageOptions = (selectedClaim?.next_statuses ?? []).map((s) => ({
        value: s,
        label: label(s),
    }));
    const isFinal = ['completed', 'rejected'].includes(selectedClaim?.status);

    return (
        <AdminLayout
            title="Warranty & RMA Service Center"
            subtitle="Manage hardware repair tickets, OEM warranty replacements, and diagnostic logs"
        >
            <Head title="Admin Warranty & RMA" />

            <div>
                <DataTable
                    title="Warranty Claims & RMA Tickets"
                    subtitle="Checked against the units the shop sold. Stages only move forward."
                    columns={columns}
                    data={claims}
                    searchable
                    searchPlaceholder="Search by RMA code, S/N, customer, or product..."
                    emptyTitle="No Warranty Claims Found"
                    emptyDescription="There are currently no repair or RMA tickets registered."
                    emptyIcon={ShieldCheck}
                />

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
                                <div className="admin-summary-box-row">
                                    <strong>Our record:</strong>{' '}
                                    <CheckTag check={selectedClaim.check} />
                                    {selectedClaim.check?.order_number &&
                                        ` · ${selectedClaim.check.order_number}`}
                                </div>
                                {selectedClaim.replacement_serial && (
                                    <div className="admin-summary-box-row">
                                        <strong>Replaced with:</strong>{' '}
                                        <code className="text-primary">
                                            {selectedClaim.replacement_serial}
                                        </code>
                                    </div>
                                )}
                                <div className="admin-summary-box-row text-muted">
                                    <strong>Reported Defect:</strong>{' '}
                                    {selectedClaim.issue_description}
                                </div>
                            </div>

                            <div className="admin-form-stack">
                                <Select
                                    label="Stage"
                                    value={updatingStatus}
                                    onChange={(e) =>
                                        setUpdatingStatus(e.target.value)
                                    }
                                    options={stageOptions}
                                    disabled={isFinal}
                                    helperText={
                                        isFinal
                                            ? `This claim is ${label(selectedClaim.status)}; it cannot move again.`
                                            : 'Stages only move forward. Rejected is possible until the claim is completed.'
                                    }
                                />

                                <div>
                                    <label className="admin-form-field-label">
                                        Notes for the customer
                                    </label>
                                    <textarea
                                        value={diagnosticNotes}
                                        onChange={(e) =>
                                            setDiagnosticNotes(e.target.value)
                                        }
                                        rows={4}
                                        className="auth-text-input"
                                        placeholder="What was found and what was done. The customer sees this on the Warranty page."
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
                                        Save
                                    </Button>
                                </div>

                                {selectedClaim.can_replace && (
                                    <div className="admin-summary-box">
                                        <div className="admin-summary-box-row">
                                            <strong>
                                                Replace with a new unit
                                            </strong>
                                        </div>
                                        {selectedClaim.replacement_options
                                            ?.length ? (
                                            <>
                                                <Select
                                                    label="Serial of the new unit"
                                                    value={replacementId}
                                                    onChange={(e) =>
                                                        setReplacementId(
                                                            e.target.value,
                                                        )
                                                    }
                                                    options={[
                                                        {
                                                            value: '',
                                                            label: 'Pick a unit on the shelf…',
                                                        },
                                                        ...selectedClaim.replacement_options.map(
                                                            (o) => ({
                                                                value: String(
                                                                    o.value,
                                                                ),
                                                                label: o.label,
                                                            }),
                                                        ),
                                                    ]}
                                                    helperText="It leaves stock and goes to the customer with the same warranty end date. The faulty unit is marked faulty."
                                                />
                                                <div className="admin-modal-action-row">
                                                    <Button
                                                        type="button"
                                                        variant="primary"
                                                        icon={RefreshCw}
                                                        onClick={handleReplace}
                                                        loading={isReplacing}
                                                        disabled={
                                                            !replacementId
                                                        }
                                                    >
                                                        Give replacement
                                                    </Button>
                                                </div>
                                            </>
                                        ) : (
                                            <div className="admin-summary-box-row text-muted">
                                                No unit of this product is on
                                                the shelf to replace it with.
                                                Receive one first.
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </Modal>
            </div>
        </AdminLayout>
    );
}
