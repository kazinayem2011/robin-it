import React, { useEffect, useState } from 'react';
import Modal from '../../../Components/Modal';
import { TableSkeleton } from '../../../Components/Skeleton';
import { adminService } from '../../../services';

/**
 * The movement history behind one balance.
 *
 * Every row says what moved, why, who did it and where the balance landed, so a
 * number that looks wrong can be traced rather than guessed at.
 */
export default function StockLedgerModal({ target, onClose }) {
    const [state, setState] = useState({
        loading: false,
        movements: [],
        integrity: null,
    });

    const product = target?.product;
    const variant = target?.variant;

    // Depend on the ids rather than the objects: `target` is rebuilt by the
    // parent on every render, so depending on it would refetch the ledger
    // continuously while the modal is open.
    const productId = product?.id ?? null;
    const variantId = variant?.id ?? null;

    useEffect(() => {
        if (!productId) return;

        let cancelled = false;
        setState({ loading: true, movements: [], integrity: null });

        adminService
            .getStockMovements(
                productId,
                variantId ? { variant_id: variantId } : {},
            )
            .then((res) => {
                if (cancelled) return;
                setState({
                    loading: false,
                    movements: res?.movements?.data || [],
                    integrity: res?.integrity || null,
                });
            })
            .catch(() => {
                if (!cancelled)
                    setState({
                        loading: false,
                        movements: [],
                        integrity: null,
                    });
            });

        return () => {
            cancelled = true;
        };
    }, [productId, variantId]);

    return (
        <Modal
            isOpen={Boolean(target)}
            onClose={onClose}
            title="Stock history"
            maxWidth="760px"
        >
            <div className="admin-adjust-summary">
                <div className="admin-adjust-name">
                    {product?.name}
                    {variant && <span> — {variant.name}</span>}
                </div>
            </div>

            {state.integrity?.drifted && (
                <div className="admin-ledger-drift">
                    The recorded movements add up to {state.integrity.expected},
                    but the shelf says {state.integrity.actual}. Something
                    changed this balance outside the ledger — worth
                    investigating.
                </div>
            )}

            {state.loading ? (
                <TableSkeleton
                    headers={[
                        'When',
                        'What happened',
                        'Change',
                        'Balance',
                        'By',
                    ]}
                    rows={6}
                />
            ) : state.movements.length === 0 ? (
                <p className="admin-field-hint">
                    Nothing has moved yet. Receiving a delivery will show up
                    here.
                </p>
            ) : (
                <div className="admin-table-responsive">
                    <table className="admin-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>What happened</th>
                                <th>Change</th>
                                <th>Balance</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            {state.movements.map((m) => (
                                <tr key={m.id}>
                                    <td>
                                        {new Date(
                                            m.created_at,
                                        ).toLocaleDateString('en-GB', {
                                            day: 'numeric',
                                            month: 'short',
                                            year: 'numeric',
                                        })}
                                    </td>
                                    <td>
                                        <div>{m.type_label}</div>
                                        {(m.reason || m.note) && (
                                            <div className="admin-field-hint">
                                                {[m.reason, m.note]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </div>
                                        )}
                                    </td>
                                    <td
                                        className={
                                            m.quantity >= 0
                                                ? 'admin-ledger-in'
                                                : 'admin-ledger-out'
                                        }
                                    >
                                        {m.quantity > 0
                                            ? `+${m.quantity}`
                                            : m.quantity}
                                    </td>
                                    <td>{m.balance_after}</td>
                                    <td>{m.user?.name || 'System'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Modal>
    );
}
