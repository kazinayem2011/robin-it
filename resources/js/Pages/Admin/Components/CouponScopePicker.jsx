import React from 'react';
import FormSelect from '../../../Components/FormSelect';

const SCOPE_LABELS = {
    all: 'The whole order',
    products: 'Selected products only',
    categories: 'Selected categories only',
};

/**
 * What a promo is allowed to discount.
 *
 * A scoped coupon only ever discounts the lines it covers, so "20% off graphics
 * cards" cannot take 20% off a basket that also holds a processor. The minimum
 * spend is measured against the same qualifying lines, which is worth saying out
 * loud here rather than leaving to be discovered at checkout.
 */
export default function CouponScopePicker({
    formik,
    products = [],
    categories = [],
}) {
    const scope = formik.values.scope || 'all';

    const toggle = (field, id) => {
        const current = formik.values[field] || [];

        formik.setFieldValue(
            field,
            current.includes(id)
                ? current.filter((x) => x !== id)
                : [...current, id],
        );
    };

    const list = scope === 'products' ? products : categories;
    const field = scope === 'products' ? 'product_ids' : 'category_ids';
    const chosen = formik.values[field] || [];

    return (
        <div className="admin-coupon-scope">
            <FormSelect
                label="Applies to"
                name="scope"
                value={scope}
                onChange={(e) => {
                    formik.setFieldValue('scope', e.target.value);
                    // Clearing both keeps a switched scope from carrying a
                    // stale restriction that would change what it discounts.
                    formik.setFieldValue('product_ids', []);
                    formik.setFieldValue('category_ids', []);
                }}
                options={Object.entries(SCOPE_LABELS).map(([value, label]) => ({
                    value,
                    label,
                }))}
            />

            {scope !== 'all' && (
                <>
                    <span className="admin-field-hint">
                        The discount applies only to these lines, and the
                        minimum spend is measured against them too.
                        {scope === 'categories' &&
                            ' Sub-categories are included automatically.'}
                    </span>

                    <div className="admin-coupon-scope-list">
                        {list.length === 0 ? (
                            <span className="admin-field-hint">
                                Nothing to choose from yet.
                            </span>
                        ) : (
                            list.map((entry) => (
                                <label
                                    key={entry.id}
                                    className={`admin-coupon-scope-chip ${
                                        chosen.includes(entry.id)
                                            ? 'is-selected'
                                            : ''
                                    }`}
                                >
                                    <input
                                        type="checkbox"
                                        className="custom-checkbox-input"
                                        checked={chosen.includes(entry.id)}
                                        onChange={() => toggle(field, entry.id)}
                                    />
                                    <span>{entry.name}</span>
                                </label>
                            ))
                        )}
                    </div>

                    {chosen.length === 0 && (
                        <span className="admin-coupon-scope-warning">
                            Pick at least one, or this code will not apply to
                            anything.
                        </span>
                    )}
                </>
            )}
        </div>
    );
}
