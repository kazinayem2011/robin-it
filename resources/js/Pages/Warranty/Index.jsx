import React, { useState, useEffect, useMemo } from 'react';

import { useFormik } from 'formik';
import * as Yup from 'yup';
import { mainLayout } from '../../Layouts/MainLayout';
import SEOHead from '../../Components/SEOHead';
import Button from '../../Components/Button';
import FormInput from '../../Components/FormInput';
import Select from '../../Components/Select';
import Tabs from '../../Components/Tabs';
import { toast } from '../../Components/Toast';
import { warrantyService, storeService } from '../../services';
import siteConfig from '../../constants/siteConfig';

import {
    ShieldCheck,
    Search,
    Truck,
    Clock,
    Wrench,
    CheckCircle2,
    AlertCircle,
    Printer,
    PackageOpen,
} from 'lucide-react';
import './Warranty.css';

const RMA_STEPS = [
    { key: 'received', label: '1. Received' },
    { key: 'diagnosing', label: '2. Diagnosing' },
    { key: 'repairing', label: '3. Repairing / OEM Claim' },
    { key: 'ready_for_pickup', label: '4. Ready for Pickup' },
    { key: 'completed', label: '5. Completed' },
];

const ISSUE_TYPES = [
    { value: 'Hardware Malfunction', label: 'Hardware Malfunction / No Boot' },
    { value: 'No Display Output', label: 'No Display Output / Black Screen' },
    {
        value: 'BSOD / Frequent Crashing',
        label: 'BSOD / Frequent System Crashing',
    },
    {
        value: 'Thermal Throttling / Overheating',
        label: 'Thermal Throttling / Fan Failure',
    },
    {
        value: 'Port / Connector Defect',
        label: 'Port / Connector Defect (HDMI/DP/USB)',
    },
    {
        value: 'DOA (Dead on Arrival)',
        label: 'DOA (Dead on Arrival within 7 Days)',
    },
    { value: 'Other Issue', label: 'Other Technical Defect' },
];

/*
 * Courier pickup is always available; the branches come from the shop's own
 * showrooms.
 *
 * This list used to be written out here, and it had drifted: it offered a
 * Sylhet branch at Zindabazar that the shop does not have, and named two
 * others differently from the stores table. Someone would have posted a faulty
 * card to an address that does not exist.
 */
const COURIER_PICKUP = {
    value: 'Doorstep Courier Pickup (All 64 Districts)',
    label: 'Doorstep Courier Pickup (Nationwide 64 Districts)',
};

const claimValidationSchema = Yup.object().shape({
    customer_name: Yup.string().required('Customer name is required'),
    customer_phone: Yup.string().required('Phone number is required'),
    customer_email: Yup.string().email('Invalid email').nullable(),
    product_name: Yup.string().required('Product name/model is required'),
    serial_number: Yup.string().required(
        'Product serial number (S/N) is required',
    ),
    invoice_number: Yup.string().nullable(),
    issue_type: Yup.string().required('Issue category is required'),
    issue_description: Yup.string()
        .min(10, 'Please provide more details (min 10 chars)')
        .required('Issue description is required'),
    dropoff_branch: Yup.string().required(
        'Please select a dropoff showroom or courier pickup',
    ),
});

export default function WarrantyIndex() {
    const [activeTab, setActiveTab] = useState('lookup'); // 'lookup' | 'claim'
    const [branches, setBranches] = useState([]);

    // The drop-off points are the shop's real showrooms, so a customer is never
    // sent to a branch that does not exist.
    useEffect(() => {
        let cancelled = false;

        storeService
            .getStores()
            .then((stores) => {
                if (cancelled) return;

                setBranches(Array.isArray(stores) ? stores : []);
            })
            .catch(() => {
                // Courier pickup still works if the list cannot be loaded.
            });

        return () => {
            cancelled = true;
        };
    }, []);

    const dropoffOptions = useMemo(() => {
        const fromStores = branches
            .filter((store) => store?.name)
            .map((store) => ({
                value: [store.name, store.address].filter(Boolean).join(', '),
                label: [store.name, store.city].filter(Boolean).join(' — '),
            }));

        return [...fromStores, COURIER_PICKUP];
    }, [branches]);
    const [searchQuery, setSearchQuery] = useState('');
    const [isSearching, setIsSearching] = useState(false);
    const [warrantyData, setWarrantyData] = useState(null);
    const [submittedClaim, setSubmittedClaim] = useState(null);

    const handleSearch = async (e) => {
        e?.preventDefault();
        if (!searchQuery.trim()) {
            toast.error('Please enter a Serial Number or Invoice Number.');
            return;
        }

        setIsSearching(true);
        try {
            const data = await warrantyService.checkWarranty(
                searchQuery.trim(),
            );
            setWarrantyData(data?.data || data);
        } catch (err) {
            toast.error(err?.message || 'No warranty record found.');
            setWarrantyData(null);
        } finally {
            setIsSearching(false);
        }
    };

    const formik = useFormik({
        initialValues: {
            customer_name: '',
            customer_phone: '',
            customer_email: '',
            product_name: '',
            serial_number: '',
            invoice_number: '',
            issue_type: 'Hardware Malfunction',
            issue_description: '',
            /* Courier pickup, because it is the one option that is always
               available — the branches load from the shop's showrooms and are
               not known when this form is initialised. */
            dropoff_branch: COURIER_PICKUP.value,
        },
        validationSchema: claimValidationSchema,
        onSubmit: async (values, { setSubmitting, resetForm }) => {
            try {
                const res = await warrantyService.submitClaim(values);
                setSubmittedClaim(res?.data || res);
                toast.success('RMA claim registered successfully!');
                resetForm();
            } catch (err) {
                toast.error(err?.message || 'Failed to submit warranty claim.');
            } finally {
                setSubmitting(false);
            }
        },
    });

    const getStepIndex = (status) => {
        const idx = RMA_STEPS.findIndex((s) => s.key === status);
        return idx !== -1 ? idx : 0;
    };

    return (
        <>
            <SEOHead
                title="Official Warranty Check & RMA Service Claim"
                description="Verify product warranty by serial number, track real-time RMA service progress, or submit an official repair claim with doorstep courier pickup."
            />

            {/* Warranty Hero Banner */}
            <section className="warranty-hero-banner">
                <div className="container warranty-hero-inner">
                    <div
                        className="badge-pill"
                        style={{ margin: '0 auto 8px' }}
                    >
                        <ShieldCheck size={14} /> Official Authorized Warranty
                        Protection
                    </div>
                    <h1>Warranty Check &amp; RMA Service Center</h1>
                    <p>
                        Verify genuine manufacturer warranty validity, track
                        active repair tickets, or file a rapid doorstep RMA
                        request with {siteConfig.name}.
                    </p>
                </div>
            </section>

            <div className="container">
                {/* Mode Selector Tabs */}
                <div style={{ maxWidth: '440px', margin: '0 auto 36px' }}>
                    <Tabs
                        tabs={[
                            {
                                key: 'lookup',
                                label: 'Check S/N & Track RMA',
                                icon: Search,
                            },
                            {
                                key: 'claim',
                                label: 'Submit RMA Claim',
                                icon: Wrench,
                            },
                        ]}
                        activeTab={activeTab}
                        onChange={setActiveTab}
                        variant="pills"
                    />
                </div>

                {/* TAB 1: S/N LOOKUP & RMA TRACKER */}
                {activeTab === 'lookup' && (
                    <>
                        <div className="warranty-lookup-card">
                            <h3
                                style={{
                                    fontSize: '1.3rem',
                                    fontWeight: 800,
                                    margin: 0,
                                }}
                            >
                                Check Warranty Expiry &amp; Track RMA Ticket
                            </h3>
                            <p
                                style={{
                                    color: 'var(--gray-600)',
                                    fontSize: '0.9rem',
                                    marginTop: '6px',
                                }}
                            >
                                Enter your Product Serial Number (S/N), Invoice
                                Number, or RMA Claim ID (e.g.{' '}
                                <code>ROG-4090-SN9823</code> or{' '}
                                <code>RMA-849201</code>)
                            </p>

                            <form
                                onSubmit={handleSearch}
                                className="warranty-input-box"
                                noValidate
                            >
                                <input
                                    type="text"
                                    placeholder="Enter Serial No / Invoice / RMA Code..."
                                    value={searchQuery}
                                    onChange={(e) =>
                                        setSearchQuery(e.target.value)
                                    }
                                    className="warranty-search-field"
                                />
                                {/*
                                 * icon=, not an icon element among the
                                 * children: Button gives the prop its own slot
                                 * and the gap that goes with it, while a child
                                 * icon lands inside the label's span and reads
                                 * as one word — "⌕Check Status".
                                 */}
                                <Button
                                    type="submit"
                                    variant="primary"
                                    icon={Search}
                                    loading={isSearching}
                                    disabled={isSearching}
                                >
                                    {isSearching
                                        ? 'Verifying…'
                                        : 'Check Status'}
                                </Button>
                            </form>
                        </div>

                        {/* Lookup Results View */}
                        {warrantyData && (
                            <div className="warranty-result-card">
                                <div className="warranty-status-header">
                                    <div>
                                        <span
                                            style={{
                                                fontSize: '0.78rem',
                                                color: 'var(--gray-500)',
                                                fontWeight: 700,
                                                textTransform: 'uppercase',
                                            }}
                                        >
                                            Query Ref: {warrantyData.query}
                                        </span>
                                        <h3
                                            style={{
                                                fontSize: '1.35rem',
                                                fontWeight: 900,
                                                margin: '4px 0 0 0',
                                            }}
                                        >
                                            {warrantyData.existing_claim
                                                ?.product_name ||
                                                'Genuine Authorized Hardware'}
                                        </h3>
                                    </div>
                                    <div>
                                        {/*
                                         * Three answers, not two. A unit still
                                         * on the shelf has no cover to be
                                         * inside or outside of, and calling it
                                         * "expired" sent people arguing about
                                         * a warranty that had not started.
                                         */}
                                        {warrantyData.not_yet_sold ? (
                                            <span className="warranty-badge-unsold">
                                                <PackageOpen size={15} /> Not
                                                sold yet
                                            </span>
                                        ) : warrantyData.is_under_warranty ? (
                                            <span className="warranty-badge-active">
                                                <CheckCircle2 size={15} />{' '}
                                                Official Warranty Active
                                            </span>
                                        ) : (
                                            <span className="warranty-badge-expired">
                                                <AlertCircle size={15} />{' '}
                                                Warranty Expired
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="warranty-metrics-grid">
                                    <div className="warranty-metric-item">
                                        <div className="warranty-metric-label">
                                            Warranty Coverage
                                        </div>
                                        <div className="warranty-metric-value">
                                            {warrantyData.warranty_period}
                                        </div>
                                    </div>
                                    <div className="warranty-metric-item">
                                        <div className="warranty-metric-label">
                                            Purchase Date
                                        </div>
                                        <div className="warranty-metric-value">
                                            {warrantyData.purchase_date}
                                        </div>
                                    </div>
                                    <div className="warranty-metric-item">
                                        <div className="warranty-metric-label">
                                            Warranty Expires
                                        </div>
                                        <div
                                            className="warranty-metric-value"
                                            style={{
                                                color: warrantyData.is_under_warranty
                                                    ? '#16a34a'
                                                    : '#dc2626',
                                            }}
                                        >
                                            {warrantyData.warranty_expiry}
                                            {warrantyData.days_remaining >
                                                0 && (
                                                <small
                                                    style={{
                                                        display: 'block',
                                                        fontSize: '0.75rem',
                                                        fontWeight: 600,
                                                        color: 'var(--gray-500)',
                                                    }}
                                                >
                                                    (
                                                    {
                                                        warrantyData.days_remaining
                                                    }{' '}
                                                    days remaining)
                                                </small>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Live RMA Stepper if active claim exists */}
                                {warrantyData.existing_claim && (
                                    <div className="rma-stepper-container">
                                        <div
                                            style={{
                                                display: 'flex',
                                                justifyContent: 'space-between',
                                                alignItems: 'center',
                                            }}
                                        >
                                            <strong
                                                style={{
                                                    fontSize: '0.92rem',
                                                    color: 'var(--dark-900)',
                                                }}
                                            >
                                                Live RMA Ticket: #
                                                {
                                                    warrantyData.existing_claim
                                                        .claim_number
                                                }
                                            </strong>
                                            <span className="badge badge-info">
                                                {
                                                    warrantyData.existing_claim
                                                        .issue_type
                                                }
                                            </span>
                                        </div>

                                        <div className="rma-stepper">
                                            {RMA_STEPS.map((step, idx) => {
                                                const currentIdx = getStepIndex(
                                                    warrantyData.existing_claim
                                                        .status,
                                                );
                                                const isDone = idx < currentIdx;
                                                const isCurrent =
                                                    idx === currentIdx;

                                                return (
                                                    <div
                                                        key={step.key}
                                                        className={`rma-step-item ${isDone ? 'step-done' : ''} ${isCurrent ? 'step-current' : ''}`}
                                                    >
                                                        <div className="rma-step-circle">
                                                            {isDone ? (
                                                                <CheckCircle2
                                                                    size={16}
                                                                />
                                                            ) : (
                                                                idx + 1
                                                            )}
                                                        </div>
                                                        <span className="rma-step-label">
                                                            {step.label}
                                                        </span>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        {warrantyData.existing_claim
                                            .diagnostic_notes && (
                                            <div
                                                style={{
                                                    marginTop: '20px',
                                                    padding: '14px',
                                                    background: '#f8fafc',
                                                    borderRadius:
                                                        'var(--radius-sm)',
                                                    border: '1px solid var(--border-color)',
                                                }}
                                            >
                                                <strong
                                                    style={{
                                                        fontSize: '0.82rem',
                                                        color: 'var(--gray-600)',
                                                        display: 'block',
                                                        marginBottom: '4px',
                                                    }}
                                                >
                                                    Technician Diagnostic Log:
                                                </strong>
                                                <p
                                                    style={{
                                                        margin: 0,
                                                        fontSize: '0.88rem',
                                                        color: 'var(--dark-800)',
                                                    }}
                                                >
                                                    {
                                                        warrantyData
                                                            .existing_claim
                                                            .diagnostic_notes
                                                    }
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </>
                )}

                {/* TAB 2: SUBMIT NEW RMA CLAIM */}
                {activeTab === 'claim' && (
                    <div className="warranty-claim-form-card">
                        {submittedClaim ? (
                            <div className="claim-confirmed-box">
                                <CheckCircle2
                                    size={54}
                                    color="#16a34a"
                                    style={{ margin: '0 auto 12px' }}
                                />
                                <h2>RMA Ticket Logged Successfully!</h2>
                                <p
                                    style={{
                                        color: 'var(--gray-600)',
                                        maxWidth: '520px',
                                        margin: '0 auto',
                                    }}
                                >
                                    Your warranty service request has been
                                    logged into the {siteConfig.name} Technical
                                    Central Lab. Please keep your RMA Ticket
                                    Code for tracking:
                                </p>
                                <div className="claim-code-pill">
                                    {submittedClaim.claim_number}
                                </div>
                                <p
                                    style={{
                                        fontSize: '0.9rem',
                                        color: 'var(--dark-800)',
                                    }}
                                >
                                    <strong>
                                        Dropoff / Inspection Center:
                                    </strong>{' '}
                                    {submittedClaim.dropoff_branch}
                                </p>
                                <div
                                    style={{
                                        marginTop: '24px',
                                        display: 'flex',
                                        gap: '12px',
                                        justifyContent: 'center',
                                    }}
                                >
                                    <button
                                        type="button"
                                        className="btn btn-secondary"
                                        onClick={() => window.print()}
                                    >
                                        <Printer size={15} /> Print Claim
                                        Receipt
                                    </button>
                                    <Button
                                        variant="primary"
                                        onClick={() => {
                                            setSubmittedClaim(null);
                                            setSearchQuery(
                                                submittedClaim.claim_number,
                                            );
                                            setActiveTab('lookup');
                                        }}
                                    >
                                        Track Live Status
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <>
                                <h2>File a Warranty Service / RMA Claim</h2>
                                <p
                                    style={{
                                        color: 'var(--gray-600)',
                                        fontSize: '0.92rem',
                                        marginBottom: '24px',
                                    }}
                                >
                                    Submit your hardware defect details. Our
                                    certified hardware diagnostic engineers will
                                    inspect and repair/replace genuine OEM
                                    components.
                                </p>

                                <form onSubmit={formik.handleSubmit} noValidate>
                                    <div className="form-row-2col">
                                        <FormInput
                                            label="Full Name"
                                            name="customer_name"
                                            required
                                            formik={formik}
                                            placeholder="e.g. Asif Rahman"
                                        />
                                        <FormInput
                                            label="Phone Number"
                                            name="customer_phone"
                                            required
                                            formik={formik}
                                            placeholder="e.g. 017XXXXXXXX"
                                        />
                                    </div>

                                    <div className="form-row-2col">
                                        <FormInput
                                            label="Email Address"
                                            name="customer_email"
                                            formik={formik}
                                            placeholder="e.g. asif@example.com"
                                        />
                                        <FormInput
                                            label={`Invoice / Order Number (if purchased from ${siteConfig.name})`}
                                            name="invoice_number"
                                            formik={formik}
                                            placeholder="e.g. RC-984210"
                                        />
                                    </div>

                                    <div className="form-row-2col">
                                        <FormInput
                                            label="Product Model & Brand"
                                            name="product_name"
                                            required
                                            formik={formik}
                                            placeholder="e.g. ASUS ROG STRIX GeForce RTX 4090 OC"
                                        />
                                        <FormInput
                                            label="Product Serial Number (S/N)"
                                            name="serial_number"
                                            required
                                            formik={formik}
                                            placeholder="Located on product barcode label"
                                        />
                                    </div>

                                    <div className="form-row-2col">
                                        <Select
                                            label="Defect / Malfunction Type"
                                            name="issue_type"
                                            required
                                            formik={formik}
                                            options={ISSUE_TYPES}
                                        />
                                        <Select
                                            label="Dropoff Showroom or Courier Pickup"
                                            name="dropoff_branch"
                                            required
                                            formik={formik}
                                            options={dropoffOptions}
                                        />
                                    </div>

                                    <div className="form-group">
                                        <label className="auth-label">
                                            Describe the exact defect / symptoms{' '}
                                            <span className="required-asterisk">
                                                *
                                            </span>
                                        </label>
                                        <textarea
                                            name="issue_description"
                                            value={
                                                formik.values.issue_description
                                            }
                                            onChange={formik.handleChange}
                                            onBlur={formik.handleBlur}
                                            rows="4"
                                            /* .form-control, .is-invalid and
                                               .invalid-feedback are Bootstrap
                                               names and this project has no
                                               Bootstrap, so the field was an
                                               unstyled browser textarea whose
                                               errors showed no colour at all.
                                               These are the project's own. */
                                            className={`form-control-input ${
                                                formik.touched
                                                    .issue_description &&
                                                formik.errors.issue_description
                                                    ? 'has-error'
                                                    : ''
                                            }`}
                                            placeholder="Please describe when the issue happens, error codes on screen, BIOS beeps, or LED indicator behaviors..."
                                        />
                                        {formik.touched.issue_description &&
                                            formik.errors.issue_description && (
                                                <div className="form-control-error">
                                                    {
                                                        formik.errors
                                                            .issue_description
                                                    }
                                                </div>
                                            )}
                                    </div>

                                    <div style={{ marginTop: '28px' }}>
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            size="lg"
                                            fullWidth
                                            icon={Wrench}
                                            loading={formik.isSubmitting}
                                            disabled={formik.isSubmitting}
                                        >
                                            {formik.isSubmitting
                                                ? 'Registering claim…'
                                                : 'Submit official warranty claim'}
                                        </Button>
                                    </div>
                                </form>
                            </>
                        )}
                    </div>
                )}

                {/* 3-Point Guarantee Trust Grid */}
                <div className="warranty-trust-grid">
                    <div className="warranty-trust-card">
                        <div className="trust-icon-wrap">
                            <Clock size={22} />
                        </div>
                        <div>
                            <h4>48-Hour Diagnostic SLA</h4>
                            <p>
                                Automated bench testing and official diagnostic
                                report delivered within 48 hours of laboratory
                                intake.
                            </p>
                        </div>
                    </div>

                    <div className="warranty-trust-card">
                        <div className="trust-icon-wrap">
                            <ShieldCheck size={22} />
                        </div>
                        <div>
                            <h4>100% Genuine OEM Replacement</h4>
                            <p>
                                Certified distributor replacements from Asus,
                                Intel, AMD, Corsair, MSI, Gigabyte, and Samsung.
                            </p>
                        </div>
                    </div>

                    <div className="warranty-trust-card">
                        <div className="trust-icon-wrap">
                            <Truck size={22} />
                        </div>
                        <div>
                            <h4>64-District Courier Logistics</h4>
                            <p>
                                Secure doorstep pickup and return delivery for
                                warranty claims anywhere across Bangladesh.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
WarrantyIndex.layout = mainLayout;
