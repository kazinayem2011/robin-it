import React, { useEffect, useState } from 'react';
import axiosInstance from '../../../services/axiosInstance';
import { API_ENDPOINTS } from '../../../constants/endpoints';
import { payloadFrom } from '../../../utils/apiPayload';

/**
 * The answers a product gives to its shelf's questions.
 *
 * Distinct from the specification editor beside it, and deliberately so. That
 * one takes free text, because a spec sheet is prose for somebody reading one
 * product: "8 (4 Performance cores, 4 Efficient cores)". This one takes only
 * the values its category has declared, because a filter needs answers two
 * products can share exactly — typed text would make "15.6 Inch" and
 * '15.6"' two filters matching one product each.
 *
 * Which questions appear is decided by the category, so choosing a different
 * one here re-asks the server rather than guessing. A category that declares
 * none shows nothing at all — most do not have them yet, and an empty panel
 * headed "Filters" would read as something broken.
 */
export default function AttributeEditor({ formik }) {
    const categoryId = formik.values.category_id;
    const chosen = formik.values.attribute_value_ids || [];

    const [attributes, setAttributes] = useState([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!categoryId) {
            setAttributes([]);

            return undefined;
        }

        let cancelled = false;

        setLoading(true);

        axiosInstance
            .get(
                API_ENDPOINTS.ADMIN.CATEGORY_ATTRIBUTES.replace(
                    '{id}',
                    categoryId,
                ),
            )
            .then((res) => {
                if (!cancelled) setAttributes(payloadFrom(res) || []);
            })
            .catch(() => {
                if (!cancelled) setAttributes([]);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [categoryId]);

    const toggle = (valueId) => {
        const next = chosen.includes(valueId)
            ? chosen.filter((id) => id !== valueId)
            : [...chosen, valueId];

        formik.setFieldValue('attribute_value_ids', next);
    };

    /*
     * A single-answer question behaves like one: ticking Wi-Fi 6 unticks
     * Wi-Fi 5. Without this a product could claim two standards and appear
     * under both filters, which is how a filtered list stops being trusted.
     */
    const choose = (attribute, valueId) => {
        const others = attribute.values.map((v) => v.id);
        const kept = chosen.filter((id) => !others.includes(id));

        formik.setFieldValue(
            'attribute_value_ids',
            chosen.includes(valueId) ? kept : [...kept, valueId],
        );
    };

    if (loading) {
        return (
            <p className="admin-form-hint">Loading this category’s filters…</p>
        );
    }

    if (!attributes.length) {
        return null;
    }

    return (
        <div className="admin-attr-editor">
            <h4 className="admin-form-section-title">Filters</h4>
            <p className="admin-form-hint">
                What a shopper can narrow by. Only the answers this category
                offers, so two products that share one are found together.
            </p>

            {attributes.map((attribute) => {
                const many = attribute.input_type === 'flags';

                return (
                    <div className="admin-attr-group" key={attribute.id}>
                        <span className="admin-attr-legend">
                            {attribute.name}
                            {attribute.unit ? ` (${attribute.unit})` : ''}
                            {many && (
                                <em className="admin-attr-hint">choose any</em>
                            )}
                        </span>

                        <div className="admin-attr-options">
                            {attribute.values.map((value) => (
                                <label
                                    key={value.id}
                                    className={`admin-attr-chip ${
                                        chosen.includes(value.id) ? 'is-on' : ''
                                    }`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={chosen.includes(value.id)}
                                        onChange={() =>
                                            many
                                                ? toggle(value.id)
                                                : choose(attribute, value.id)
                                        }
                                    />
                                    {value.label}
                                </label>
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
