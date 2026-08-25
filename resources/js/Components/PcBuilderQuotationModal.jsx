import React, { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
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
    const [clientName, setClientName] = useState('');
    const [clientPhone, setClientPhone] = useState('');

    /*
     * The reference was computed in the render body, so any re-render produced
     * a different number — the quotation would renumber itself as soon as
     * anything on the page changed. It is issued once, when the sheet opens.
     *
     * Six random digits alone collide often enough to matter for a shop that
     * issues these daily, so the date leads and only the tail is random: the
     * number sorts by day and two quotes only clash if they are raised on the
     * same date and draw the same tail.
     */
    const [quoteRef, setQuoteRef] = useState('');

    useEffect(() => {
        if (!isOpen) return;

        const now = new Date();
        const stamp = [
            String(now.getFullYear()).slice(2),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getDate()).padStart(2, '0'),
        ].join('');
        const tail = Math.floor(1000 + Math.random() * 9000);

        setQuoteRef(`EST-${stamp}-${tail}`);
    }, [isOpen]);

    const today = new Date().toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

    /*
     * Printing used to hide the rest of the page with visibility:hidden, which
     * leaves those elements taking up their space, and pinned the sheet with
     * position:fixed, which browsers render on the first page only — so a
     * quotation ran off the bottom and the pages before it came out blank.
     *
     * Rendering into <body> instead lets the print stylesheet remove the app
     * from the layout entirely and leave the sheet in normal flow, where it
     * paginates like any other document. Marking the body while it is open is
     * what gives that CSS something to hook on to.
     */
    useEffect(() => {
        if (!isOpen) return;

        document.body.classList.add('is-printing-quotation');

        return () => document.body.classList.remove('is-printing-quotation');
    }, [isOpen]);

    if (!isOpen) return null;

    const handlePrint = () => {
        window.print();
    };

    return createPortal(
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
                    {/*
                     * The sheet displayed a customer name and phone but offered
                     * no way to enter either, so every "official quotation"
                     * went out addressed to "Valued Customer". These live in
                     * the toolbar, which the print stylesheet already hides.
                     */}
                    <div className="quotation-client-fields">
                        <input
                            type="text"
                            value={clientName}
                            onChange={(e) => setClientName(e.target.value)}
                            placeholder="Customer name"
                            aria-label="Customer name for this quotation"
                        />
                        <input
                            type="tel"
                            value={clientPhone}
                            onChange={(e) => setClientPhone(e.target.value)}
                            placeholder="Contact phone"
                            aria-label="Customer phone for this quotation"
                        />
                    </div>

                    <div className="quotation-toolbar-actions">
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
                            <strong>Quotation For:</strong>{' '}
                            {clientName.trim() || 'Valued Customer'}
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
                                                entry.category_slug ||
                                                entry.componentId ||
                                                '—'}
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
                                {siteConfig.name}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
};

export default PcBuilderQuotationModal;
