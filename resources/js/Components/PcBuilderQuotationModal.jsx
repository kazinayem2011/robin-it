import React, { useState } from 'react';
import { formatBdt } from '../utils/formatters';
import siteConfig from '../constants/siteConfig';
import {
    Printer,
    X,
    FileText,
    CheckCircle2,
    Zap,
    ShieldCheck,
    QrCode,
} from 'lucide-react';
import './PcBuilderQuotationModal.css';

export const PcBuilderQuotationModal = ({
    isOpen,
    onClose,
    components = [],
    totalPrice = 0,
    estimatedWattage = 450,
}) => {
    const [clientName, setClientName] = useState('Valued Customer');
    const [clientPhone, setClientPhone] = useState('');
    const quoteRef = `EST-${Math.floor(100000 + Math.random() * 900000)}`;
    const today = new Date().toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

    if (!isOpen) return null;

    const handlePrint = () => {
        window.print();
    };

    return (
        <div className="quotation-modal-backdrop" onClick={onClose}>
            <div
                className="quotation-sheet-container"
                onClick={(e) => e.stopPropagation()}
            >
                {/* On-screen Toolbar */}
                <div className="quotation-sheet-toolbar">
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '8px',
                        }}
                    >
                        <FileText size={18} className="text-primary" />
                        <strong style={{ fontSize: '0.95rem' }}>
                            Official Hardware Price Quotation Sheet
                        </strong>
                    </div>
                    <div style={{ display: 'flex', gap: '10px' }}>
                        <button
                            type="button"
                            className="btn btn-primary btn-sm"
                            onClick={handlePrint}
                        >
                            <Printer size={14} /> Print / Save PDF
                        </button>
                        <button
                            type="button"
                            className="btn btn-secondary btn-sm"
                            onClick={onClose}
                        >
                            <X size={14} /> Close
                        </button>
                    </div>
                </div>

                {/* Printable Quotation Content */}
                <div className="quotation-sheet-body">
                    {/* Letterhead */}
                    <div className="quotation-letterhead">
                        <div className="quotation-company-info">
                            <h2>{siteConfig.name}</h2>
                            <p>{siteConfig.tagline}</p>
                            <p>
                                Central Operations: Multiplan Computer City, New
                                Elephant Road, Dhaka-1205
                            </p>
                            <p>
                                Corporate Hotline: {siteConfig.hotline} | Web:{' '}
                                {typeof window !== 'undefined'
                                    ? window.location.origin
                                    : 'robin-it.com'}
                            </p>
                        </div>
                        <div className="quotation-meta-box">
                            <span className="quotation-badge-title">
                                Official Quotation
                            </span>
                            <div className="quotation-ref-text">
                                Ref: #{quoteRef}
                            </div>
                            <div className="quotation-date-text">
                                Date: {today}
                            </div>
                            <div
                                className="quotation-date-text"
                                style={{ color: '#16a34a', fontWeight: 600 }}
                            >
                                Validity: 7 Days
                            </div>
                        </div>
                    </div>

                    {/* Client Information */}
                    <div className="quotation-client-bar">
                        <div>
                            <strong>Quotation For:</strong> {clientName}
                        </div>
                        {clientPhone && (
                            <div>
                                <strong>Contact Phone:</strong> {clientPhone}
                            </div>
                        )}
                        <div>
                            <strong>Custom Build Type:</strong> High-Performance
                            Desktop Rig
                        </div>
                    </div>

                    {/* Itemized Hardware Specifications Table */}
                    <table className="quotation-table">
                        <thead>
                            <tr>
                                <th>Component</th>
                                <th>
                                    Hardware Description &amp; Specifications
                                </th>
                                <th style={{ textAlign: 'right' }}>
                                    Price (BDT)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {components.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan="3"
                                        style={{
                                            textAlign: 'center',
                                            padding: '30px',
                                            color: '#64748b',
                                        }}
                                    >
                                        No components selected in PC Builder.
                                    </td>
                                </tr>
                            ) : (
                                components.map((entry, idx) => (
                                    <tr key={idx}>
                                        <td className="quotation-cat-cell">
                                            {entry.category_name ||
                                                entry.category_slug}
                                        </td>
                                        <td>
                                            <p className="quotation-prod-title">
                                                {entry.product.name}
                                            </p>
                                            <div className="quotation-prod-warranty">
                                                ✓ Official Manufacturer Warranty
                                            </div>
                                        </td>
                                        <td className="quotation-price-cell">
                                            {formatBdt(
                                                entry.product.discount_price ||
                                                    entry.product.price,
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>

                    {/* Power Rating & Totals Summary */}
                    <div className="quotation-summary-grid">
                        <div className="quotation-power-badge">
                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '6px',
                                    fontWeight: 800,
                                    marginBottom: '4px',
                                }}
                            >
                                <Zap size={14} color="#16a34a" /> Power System
                                Calculation:
                            </div>
                            <div>
                                Estimated System Load:{' '}
                                <strong>{estimatedWattage} Watts</strong>
                            </div>
                            <div
                                style={{
                                    fontSize: '0.75rem',
                                    marginTop: '2px',
                                    color: '#475569',
                                }}
                            >
                                Recommended PSU:{' '}
                                <strong>
                                    {Math.ceil((estimatedWattage * 1.3) / 50) *
                                        50}
                                    W 80+ Certified
                                </strong>
                            </div>
                        </div>

                        <div className="quotation-calc-table">
                            <div className="quotation-calc-row">
                                <span>Subtotal Hardware:</span>
                                <strong>{formatBdt(totalPrice)}</strong>
                            </div>
                            <div className="quotation-calc-row">
                                <span>
                                    Professional Assembly &amp; Thermal Pasting:
                                </span>
                                <strong style={{ color: '#16a34a' }}>
                                    FREE (৳0)
                                </strong>
                            </div>
                            <div className="quotation-calc-row">
                                <span>
                                    Stress Test &amp; 24H Burn-in Diagnostic:
                                </span>
                                <strong style={{ color: '#16a34a' }}>
                                    FREE (৳0)
                                </strong>
                            </div>
                            <div className="quotation-calc-row total-row">
                                <span>Grand Total:</span>
                                <span>{formatBdt(totalPrice)}</span>
                            </div>
                        </div>
                    </div>

                    {/* Official Terms & Notes */}
                    <div className="quotation-terms">
                        <strong>Terms &amp; Conditions:</strong>
                        <ol
                            style={{ margin: '4px 0 0 0', paddingLeft: '16px' }}
                        >
                            <li>
                                Price quotation is valid for 7 calendar days
                                from issuance date due to international exchange
                                rates.
                            </li>
                            <li>
                                All products include genuine distributor
                                warranty backed by official service centers
                                across Bangladesh.
                            </li>
                            <li>
                                Complimentary doorstep delivery available across
                                all 64 districts for pre-built rigs.
                            </li>
                        </ol>
                    </div>

                    {/* Signatures */}
                    <div className="quotation-footer-signatures">
                        <div className="signature-box">Customer Acceptance</div>
                        <div className="signature-box">
                            Authorized Representative
                            <div
                                style={{
                                    fontSize: '0.7rem',
                                    color: '#16a34a',
                                    fontWeight: 600,
                                    marginTop: '2px',
                                }}
                            >
                                {siteConfig.name} Systems Lab
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default PcBuilderQuotationModal;
