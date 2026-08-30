import React from 'react';
import Button from '../../../Components/Button';
import { Plus, Trash2, GripVertical } from 'lucide-react';

const newRow = () => ({
    key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
    group: '',
    name: '',
    value: '',
});

/**
 * The spec sheet.
 *
 * The table has existed since the first migration and the storefront has always
 * rendered it, but nothing in the admin could write a row — every specification
 * on the site arrived from a seeder. This is that missing screen.
 *
 * Three columns rather than two, because a laptop's forty specs are unreadable
 * as one flat list. `group` is the heading a row sits under ("Processor",
 * "Display"); leaving it blank is normal for a product with six specs and no
 * need for sections.
 *
 * Order is the order of the rows here. It is sent as the array index, so moving
 * a row moves it on the product page too.
 */
const SpecificationEditor = ({ formik }) => {
    const rows = formik.values.specifications ?? [];

    const setRows = (next) => formik.setFieldValue('specifications', next);

    const updateRow = (key, field, val) =>
        setRows(
            rows.map((row) =>
                row.key === key ? { ...row, [field]: val } : row,
            ),
        );

    const addRow = () => setRows([...rows, newRow()]);

    const removeRow = (key) => setRows(rows.filter((row) => row.key !== key));

    /*
     * Carrying the previous row's heading down means a ten-row "Display"
     * section is typed once, not ten times. Only used for a brand new row, so
     * it never overwrites something already entered.
     */
    const addRowUnderSameGroup = () => {
        const last = rows[rows.length - 1];
        setRows([...rows, { ...newRow(), group: last?.group ?? '' }]);
    };

    return (
        <div className="admin-spec-editor">
            <div className="admin-spec-header">
                <label className="auth-label">Specifications</label>
                <span className="admin-field-hint">
                    Group is the heading a row appears under on the product page
                    — Processor, Display, Ports. Leave it blank for a short
                    list.
                </span>
            </div>

            {rows.length > 0 && (
                <div className="admin-spec-list">
                    <div className="admin-spec-row admin-spec-row-head">
                        <span
                            className="admin-spec-handle"
                            aria-hidden="true"
                        />
                        <span>Group</span>
                        <span>Name</span>
                        <span>Value</span>
                        <span className="admin-spec-remove-head" />
                    </div>

                    {rows.map((row, index) => (
                        <div className="admin-spec-row" key={row.key}>
                            <span
                                className="admin-spec-handle"
                                aria-hidden="true"
                            >
                                <GripVertical size={14} />
                            </span>

                            <input
                                type="text"
                                className="auth-input"
                                aria-label={`Specification ${index + 1} group`}
                                placeholder="Processor"
                                value={row.group}
                                onChange={(e) =>
                                    updateRow(row.key, 'group', e.target.value)
                                }
                            />

                            <input
                                type="text"
                                className="auth-input"
                                aria-label={`Specification ${index + 1} name`}
                                placeholder="Processor Model"
                                value={row.name}
                                onChange={(e) =>
                                    updateRow(row.key, 'name', e.target.value)
                                }
                            />

                            <input
                                type="text"
                                className="auth-input"
                                aria-label={`Specification ${index + 1} value`}
                                placeholder="Intel Core i7-14700HX"
                                value={row.value}
                                onChange={(e) =>
                                    updateRow(row.key, 'value', e.target.value)
                                }
                            />

                            <button
                                type="button"
                                className="admin-receive-line-remove"
                                onClick={() => removeRow(row.key)}
                                aria-label={`Remove specification ${index + 1}`}
                            >
                                <Trash2 size={14} />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            <div className="admin-spec-actions">
                <Button
                    type="button"
                    variant="secondary"
                    icon={Plus}
                    onClick={addRow}
                >
                    Add specification
                </Button>

                {rows.length > 0 && (
                    <Button
                        type="button"
                        variant="secondary"
                        icon={Plus}
                        onClick={addRowUnderSameGroup}
                    >
                        Add to same group
                    </Button>
                )}
            </div>

            {/* A half-typed row is dropped on save rather than refused, so say so
                here — otherwise a row silently disappearing looks like data loss. */}
            <span className="admin-field-hint">
                Rows missing a name or a value are not saved.
            </span>
        </div>
    );
};

export default SpecificationEditor;
