import React from 'react';
import { Printer } from 'lucide-react';
import { Modal } from '@/Components/Modal';
import { StatusBadge } from '@/Components/StatusBadge';
import { formatBdt, formatDate } from '@/utils/formatters';

/**
 * Shared by the orders list and the overview, which both offer "view details" —
 * one copy rather than two that drift apart.
 */
export default function OrderInvoiceModal({ selectedOrder, setSelectedOrder }) {
    return (
        <Modal
            isOpen={!!selectedOrder}
            onClose={() => setSelectedOrder(null)}
            title={`Order Invoice #${selectedOrder?.order_number || ''}`}
            maxWidth="560px"
            footer={
                <button
                    className="btn btn-primary"
                    onClick={() => setSelectedOrder(null)}
                >
                    Close Invoice
                </button>
            }
        >
            {selectedOrder && (
                <div>
                    {/* The account has promised "invoices" all along
                        without producing one. */}
                    <a
                        href={`/orders/${selectedOrder.id}/invoice`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="dash-invoice-link"
                    >
                        <Printer size={15} /> Print invoice
                    </a>

                    <div className="dash-modal-invoice-header">
                        <div>
                            <span className="dash-modal-invoice-date">
                                Placed on {formatDate(selectedOrder.created_at)}
                            </span>
                        </div>
                        <StatusBadge status={selectedOrder.status} />
                    </div>

                    <h4 className="dash-modal-items-heading">Items Summary</h4>
                    <div className="dash-modal-items-list">
                        {selectedOrder.items?.map((item) => (
                            <div key={item.id} className="dash-modal-item-row">
                                <div className="dash-modal-item-info">
                                    <strong>{item.product_name}</strong>
                                    <div className="dash-modal-item-meta">
                                        Qty: {item.quantity} ×{' '}
                                        {formatBdt(item.price)}
                                    </div>
                                </div>
                                <strong className="dash-modal-item-total">
                                    {formatBdt(item.total)}
                                </strong>
                            </div>
                        ))}
                    </div>

                    <div className="dash-modal-summary-box">
                        <div className="dash-modal-summary-row">
                            <span>Subtotal:</span>
                            <span>{formatBdt(selectedOrder.subtotal)}</span>
                        </div>
                        <div className="dash-modal-summary-row">
                            <span>Shipping:</span>
                            <span>
                                {selectedOrder.shipping_fee > 0
                                    ? formatBdt(selectedOrder.shipping_fee)
                                    : 'FREE'}
                            </span>
                        </div>
                        <div className="dash-modal-summary-total">
                            <span>Total Amount:</span>
                            <span>{formatBdt(selectedOrder.total)}</span>
                        </div>
                    </div>
                </div>
            )}
        </Modal>
    );
}
