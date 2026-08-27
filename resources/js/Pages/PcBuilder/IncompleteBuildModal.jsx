import React from 'react';
import { AlertTriangle, ShoppingCart } from 'lucide-react';
import Button from '../../Components/Button';
import Modal from '../../Components/Modal';

/**
 * Shown when someone adds a rig that is missing parts it cannot boot without.
 *
 * It does not refuse the order. A good share of this shop's builds are
 * upgrades — a processor and board going into a machine that already has a
 * case and a supply — and a builder that blocks those is a builder people stop
 * using. What it does is make sure nobody finds out at the bench that the box
 * they bought has no power supply in it.
 */
const IncompleteBuildModal = ({
    isOpen,
    onClose,
    onConfirm,
    onLocateMissing,
    missing = [],
    adding = false,
}) => (
    <Modal
        isOpen={isOpen}
        onClose={onClose}
        title="This build is missing essential parts"
        maxWidth="560px"
        footer={
            <>
                <Button
                    variant="secondary"
                    onClick={onLocateMissing || onClose}
                >
                    Choose the missing parts
                </Button>
                <Button
                    variant="primary"
                    icon={ShoppingCart}
                    loading={adding}
                    onClick={onConfirm}
                >
                    Add anyway
                </Button>
            </>
        }
    >
        <div className="build-gap-notice">
            <AlertTriangle size={18} />
            <p>
                A working computer needs all of these. Add the rig as it stands
                only if you already own the rest.
            </p>
        </div>

        <ul className="build-gap-list">
            {missing.map((slot) => (
                <li key={slot.id}>{slot.name}</li>
            ))}
        </ul>
    </Modal>
);

export default IncompleteBuildModal;
