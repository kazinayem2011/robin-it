import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Megaphone, Plus, Send, Trash2, AlertTriangle } from 'lucide-react';
import Button from '@/Components/Button';
import DataTable from '@/Components/DataTable';
import Pagination from '@/Components/Pagination';
import Tabs from '@/Components/Tabs';
import Modal from '@/Components/Modal';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import './Campaigns.css';

/**
 * Telling every customer about something at once.
 *
 * The shop had been collecting a mailing list for a year with nowhere to send
 * it, and could only text a customer about their own order.
 *
 * The screen is built around the two questions worth asking before a blast:
 * who is this actually going to, and what will the texts cost. Both are
 * answered before the send button does anything.
 */
export default function Campaigns({
    campaigns = { data: [] },
    filters = {},
    statuses = {},
    channels = {},
    audiences = {},
}) {
    const [writing, setWriting] = useState(false);

    const go = (params) =>
        router.get(
            '/admin/campaigns',
            { ...filters, ...params },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    const refresh = () => router.reload({ only: ['campaigns'] });

    const send = async (campaign) => {
        if (
            !window.confirm(
                `Send "${campaign.title}" now? It goes to everyone in that audience and cannot be recalled.`,
            )
        ) {
            return;
        }

        try {
            const res = await adminService.sendCampaign(campaign.id);
            toast.success(res?.message || 'On its way.');
            refresh();
        } catch (err) {
            toast.error(err?.message || 'Could not send that campaign.');
        }
    };

    const remove = async (campaign) => {
        if (!window.confirm(`Delete "${campaign.title}"?`)) return;

        try {
            await adminService.deleteCampaign(campaign.id);
            toast.success('Campaign deleted.');
            refresh();
        } catch (err) {
            toast.error(err?.message || 'Could not delete that.');
        }
    };

    const columns = [
        {
            key: 'title',
            header: 'Campaign',
            render: (c) => (
                <div>
                    <strong className="admin-table-item-title">
                        {c.title}
                    </strong>
                    <div className="admin-field-hint">
                        {c.channel_label} · {c.audience_label}
                    </div>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Where it is',
            render: (c) => (
                <div>
                    <span className={`cmp-badge cmp-${c.status}`}>
                        {c.status_label}
                    </span>
                    {c.status === 'sending' && (
                        <div className="admin-field-hint">
                            {c.sent_count} of {c.recipient_count} done
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'reach',
            header: 'Reached',
            render: (c) =>
                c.recipient_count > 0 ? (
                    <div>
                        <strong>{c.sent_count}</strong>
                        <span className="admin-field-hint">
                            {' '}
                            / {c.recipient_count}
                        </span>
                        {c.failed_count > 0 && (
                            <div className="cmp-failed">
                                {c.failed_count} failed
                            </div>
                        )}
                    </div>
                ) : (
                    <span className="admin-field-hint">—</span>
                ),
        },
        {
            key: 'parts',
            header: 'SMS parts',
            render: (c) =>
                c.sms_parts > 0 ? (
                    c.sms_parts
                ) : (
                    <span className="admin-field-hint">—</span>
                ),
        },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (c) => (
                <div className="admin-input-row-flex">
                    {c.status === 'draft' && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Send it now"
                            onClick={() => send(c)}
                        >
                            <Send size={14} />
                        </button>
                    )}
                    {c.status !== 'sending' && (
                        <button
                            type="button"
                            className="admin-table-icon-btn"
                            title="Delete"
                            onClick={() => remove(c)}
                        >
                            <Trash2 size={14} />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AdminLayout
            title="Campaigns"
            subtitle="One message to the whole list, by email or text"
        >
            <Head title="Campaigns" />

            <Tabs
                variant="enclosed"
                tabs={[
                    { key: '', label: 'All' },
                    ...Object.entries(statuses).map(([key, label]) => ({
                        key,
                        label,
                    })),
                ]}
                activeTab={filters.status || ''}
                onChange={(status) => go({ status: status || undefined })}
            />

            <DataTable
                columns={columns}
                data={campaigns.data ?? []}
                title="Campaigns"
                subtitle="A draft can be rewritten; once it has gone out it cannot be recalled or corrected"
                headerActions={
                    <Button
                        variant="primary"
                        size="sm"
                        icon={Plus}
                        onClick={() => setWriting(true)}
                    >
                        Write one
                    </Button>
                }
                emptyTitle="No campaigns yet"
                emptyDescription="Write one to tell your mailing list or your customers about a sale, a new arrival, or a change of hours."
                emptyIcon={Megaphone}
                pagination={false}
            />

            {campaigns.last_page > 1 && (
                <Pagination
                    links={campaigns.links}
                    currentPage={campaigns.current_page}
                    totalPages={campaigns.last_page}
                    from={campaigns.from}
                    to={campaigns.to}
                    total={campaigns.total}
                />
            )}

            <WriteCampaignModal
                open={writing}
                channels={channels}
                audiences={audiences}
                onClose={() => setWriting(false)}
                onSaved={() => {
                    setWriting(false);
                    refresh();
                }}
            />
        </AdminLayout>
    );
}

/**
 * Writing one, with the reach and the bill shown before it is saved.
 *
 * The estimate is the point of this screen. One em dash in a sentence pushes
 * the whole text into unicode, where 70 characters fit instead of 160 — on a
 * few thousand numbers that is a doubled invoice for a character nobody saw.
 */
function WriteCampaignModal({ open, channels, audiences, onClose, onSaved }) {
    const [form, setForm] = useState({
        title: '',
        subject: '',
        body: '',
        channel: 'email',
        audience: 'subscribers',
    });
    const [estimate, setEstimate] = useState(null);
    const [checking, setChecking] = useState(false);
    const [saving, setSaving] = useState(false);

    const set = (field) => (e) => {
        setForm((prev) => ({ ...prev, [field]: e.target.value }));
        // Any edit invalidates the last estimate; showing a stale one beside a
        // changed message is worse than showing none.
        setEstimate(null);
    };

    const check = async () => {
        setChecking(true);

        try {
            const res = await adminService.previewCampaign(form);
            setEstimate(res?.data ?? res);
        } catch (err) {
            toast.error(err?.message || 'Could not work that out.');
        } finally {
            setChecking(false);
        }
    };

    const save = async () => {
        setSaving(true);

        try {
            const res = await adminService.createCampaign(form);
            toast.success(res?.message || 'Saved as a draft.');
            setForm({
                title: '',
                subject: '',
                body: '',
                channel: 'email',
                audience: 'subscribers',
            });
            setEstimate(null);
            onSaved();
        } catch (err) {
            toast.error(err?.message || 'Could not save that.');
        } finally {
            setSaving(false);
        }
    };

    const needsSubject = form.channel === 'email' || form.channel === 'both';
    const complete =
        form.title.trim() &&
        form.body.trim() &&
        (!needsSubject || form.subject.trim());

    return (
        <Modal
            isOpen={open}
            onClose={onClose}
            title="Write a campaign"
            maxWidth="620px"
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="secondary"
                        onClick={check}
                        loading={checking}
                        disabled={!complete}
                    >
                        Who gets this?
                    </Button>
                    <Button
                        variant="primary"
                        onClick={save}
                        loading={saving}
                        disabled={!complete}
                    >
                        Save as draft
                    </Button>
                </>
            }
        >
            <FormInput
                label="Name it"
                name="cmp_title"
                required
                value={form.title}
                onChange={set('title')}
                placeholder="For your own list — nobody else sees this"
            />

            <div className="cmp-row">
                <FormSelect
                    label="Send by"
                    name="cmp_channel"
                    required
                    value={form.channel}
                    onChange={set('channel')}
                    options={Object.entries(channels).map(([value, label]) => ({
                        value,
                        label,
                    }))}
                />

                <FormSelect
                    label="Who to"
                    name="cmp_audience"
                    required
                    value={form.audience}
                    onChange={set('audience')}
                    options={Object.entries(audiences).map(
                        ([value, label]) => ({
                            value,
                            label,
                        }),
                    )}
                />
            </div>

            {needsSubject && (
                <FormInput
                    label="Subject line"
                    name="cmp_subject"
                    required
                    value={form.subject}
                    onChange={set('subject')}
                    placeholder="What they see before they open it"
                />
            )}

            <FormInput
                label="Message"
                name="cmp_body"
                type="textarea"
                rows={7}
                required
                value={form.body}
                onChange={set('body')}
                placeholder="Write it as you would say it."
                helperText="Put {name} anywhere you want their name. Texts are prefixed with the shop's name automatically."
            />

            {estimate && (
                <div className="cmp-estimate">
                    <h4>This would go to {estimate.people} people</h4>

                    <ul>
                        {estimate.emails > 0 && (
                            <li>
                                <strong>{estimate.emails}</strong> emails
                            </li>
                        )}
                        {estimate.texts > 0 && (
                            <li>
                                <strong>{estimate.texts}</strong> text messages,
                                billed as{' '}
                                <strong>{estimate.sms_parts} parts</strong>
                            </li>
                        )}
                        {estimate.emails === 0 && estimate.texts === 0 && (
                            <li>
                                Nobody — check that people have opted in, and
                                that a text campaign has customers with numbers.
                            </li>
                        )}
                    </ul>

                    {/*
                     * The single most expensive detail, and the easiest to
                     * introduce by accident with one curly quote pasted in
                     * from a word processor.
                     */}
                    {estimate.unicode && (
                        <p className="cmp-warning">
                            <AlertTriangle size={14} />
                            This text is not plain English, so only 70
                            characters fit per part instead of 160 — it costs
                            about twice as much. Usually a dash, a curly quote
                            or an emoji pasted in from somewhere else.
                        </p>
                    )}
                </div>
            )}
        </Modal>
    );
}
