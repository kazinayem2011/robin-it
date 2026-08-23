import React, { useEffect, useState } from 'react';
import { MapPin, Phone } from 'lucide-react';
import productService from '../services/productService';

/**
 * Which showrooms are holding this.
 *
 * The single most common phone question in a shop with branches. Deliberately
 * says "available" rather than a count: a showroom figure is out of date the
 * moment someone walks in with one, and a promised "3 left" that turns out to
 * be zero is worse than no number at all.
 */
export default function BranchAvailability({ productId, variantId = null }) {
    const [branches, setBranches] = useState([]);
    const [loaded, setLoaded] = useState(false);

    useEffect(() => {
        if (!productId) return;

        let cancelled = false;
        setLoaded(false);

        productService
            .getBranchAvailability(productId, variantId)
            .then((rows) => {
                if (cancelled) return;
                setBranches(Array.isArray(rows) ? rows : []);
                setLoaded(true);
            })
            .catch(() => {
                if (!cancelled) {
                    setBranches([]);
                    setLoaded(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [productId, variantId]);

    // Nothing to say until we know, and nothing worth saying if no branch has it.
    if (!loaded || branches.length === 0) {
        return null;
    }

    return (
        <div className="pdp-branches">
            <span className="pdp-branches-label">
                <MapPin size={14} /> Also available to see at
            </span>

            <ul className="pdp-branch-list">
                {branches.map((branch) => (
                    <li key={branch.store}>
                        <div>
                            <strong>{branch.store}</strong>
                            {branch.address && (
                                <span>
                                    {branch.address}
                                    {branch.city ? `, ${branch.city}` : ''}
                                </span>
                            )}
                        </div>
                        {branch.phone && (
                            <a
                                href={`tel:${branch.phone.replace(/[^\d+]/g, '')}`}
                                className="pdp-branch-phone"
                            >
                                <Phone size={13} />
                                {branch.phone}
                            </a>
                        )}
                    </li>
                ))}
            </ul>

            <span className="pdp-branches-note">
                Branch stock changes through the day — worth calling ahead.
            </span>
        </div>
    );
}
