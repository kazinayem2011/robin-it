import React, { useCallback, useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import { Mail, MessageSquare, Eye, Send, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import Modal from '@/Components/Modal';
import RichTextEditor from '@/Components/RichTextEditor';
import Tabs from '@/Components/Tabs';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';

/**
 * What the shop says, and the two ways to check it before a customer reads it.
 *
 * The wording used to be seven Blade files and nine strings in code, so
 * changing a sentence meant a developer and a deploy — which in practice meant
 * it never changed.
 *
 * Preview and test are not decoration here. An email is written in a browser
 * and read in Outlook; a text is written in a box that shows no cost and sent
 * through a gateway that charges by the part, where one Bengali character drops
 * a message from 160 characters to 70. Both are invisible while writing, which
 * is the only moment they can still be fixed.
 */
export default function MessageTemplates({
    emailTemplates = [],
    smsTemplates = [],
    samples = {},
    smsEnabled = false,
}) {
    const [kind, setKind] = useState('email');
    const [editing, setEditing] = useState(null);
    const [draft, setDraft] = useState({ subject: '', body: '' });
    const [saving, setSaving] = useState(false);

    const [preview, setPreview] = useState(null);
    const [testTo, setTestTo] = useState('');
    const [sending, setSending] = useState(false);

    const templates = kind === 'email' ? emailTemplates : smsTemplates;

    /* Grouped the way the shop thinks about them: Orders, Account, Support. */
    const groups = useMemo(() => {
        const out = new Map();
        for (const t of templates) {
            if (!out.has(t.group)) out.set(t.group, []);
            out.get(t.group).push(t);
        }
        return [...out.entries()];
    }, [templates]);

    const open = useCallback((template) => {
        setEditing(template);
        setDraft({
            subject: template.subject || '',
            body: template.body || '',
        });
        setPreview(null);
        setTestTo('');
    }, []);

    const close = useCallback(() => {
        setEditing(null);
        setPreview(null);
    }, []);

    /*
     * What a text message costs, worked out while it is typed. The gateway
     * charges by the part, so three words added here can double what every
     * order costs — and nothing on the screen would otherwise say so.
     *
     * Counted on the filled message: a written `{order_number}` is fourteen
     * characters and an order number is eight, so counting the template
     * overstates every one of them.
     */
    const cost = useMemo(() => {
        if (kind !== 'sms' || !editing) return null;

        let filled = draft.body;
        for (const [name, value] of Object.entries(samples)) {
            filled = filled.split(`{${name}}`).join(String(value ?? ''));
        }

        const length = [...filled].length;
        /*
         * Close enough while typing; the server's count is authoritative and
         * arrives with the preview. Anything outside plain ASCII puts the
         * gateway on its 70-character alphabet, and in this shop that means
         * the first Bengali letter — which is every one of these messages.
         */
        const unicode = [...filled].some((ch) => ch.codePointAt(0) > 127);
        const single = unicode ? 70 : 160;
        const perPart = unicode ? 67 : 153;
        const parts =
            length === 0
                ? 0
                : length <= single
                  ? 1
                  : Math.ceil(length / perPart);

        return { length, parts, unicode };
    }, [kind, editing, draft.body, samples]);

    const variables = editing?.variables || [];

    const save = async () => {
        setSaving(true);
        try {
            await adminService.updateTemplate(kind, editing.id, {
                subject: kind === 'email' ? draft.subject : undefined,
                body: draft.body,
            });
            toast.success('Template saved.', 'Saved');
            close();
            window.location.reload();
        } catch (err) {
            toast.error(
                err?.message || 'Could not save that template.',
                'Not saved',
            );
        } finally {
            setSaving(false);
        }
    };

    const showPreview = async () => {
        try {
            const res = await adminService.previewTemplate(kind, editing.id);
            setPreview(res?.data ?? res);
        } catch (err) {
            toast.error(
                err?.message || 'Could not build the preview.',
                'Preview',
            );
        }
    };

    const sendTest = async () => {
        setSending(true);
        try {
            const res = await adminService.sendTemplateTest(
                kind,
                editing.id,
                testTo.trim(),
            );
            toast.success(res?.message || 'Test sent.', 'Sent');
        } catch (err) {
            toast.error(
                err?.message || 'Could not send that test.',
                'Not sent',
            );
        } finally {
            setSending(false);
        }
    };

    return (
        <AdminLayout
            title="Message Templates"
            subtitle="What the shop says when it emails or texts a customer"
        >
            <Head title="Message Templates" />

            <Tabs
                tabs={[
                    {
                        key: 'email',
                        label: 'Email',
                        icon: Mail,
                        badge: emailTemplates.length,
                    },
                    {
                        key: 'sms',
                        label: 'SMS',
                        icon: MessageSquare,
                        badge: smsTemplates.length,
                    },
                ]}
                activeTab={kind}
                onChange={setKind}
            />

            {kind === 'sms' && !smsEnabled && (
                <div className="admin-alert-banner">
                    SMS is switched off, so none of these are being sent. Turn
                    it on in Settings → SMS once your gateway details are in.
                </div>
            )}

            {groups.map(([group, rows]) => (
                <div key={group} className="admin-card">
                    <div className="admin-card-header">
                        <h3 className="admin-card-title">{group}</h3>
                    </div>

                    <ul className="tpl-list">
                        {rows.map((template) => (
                            <li key={template.id} className="tpl-row">
                                <div className="tpl-what">
                                    <strong className="tpl-name">
                                        {template.name}
                                    </strong>
                                    {template.hint && (
                                        <span className="tpl-hint">
                                            {template.hint}
                                        </span>
                                    )}
                                    {kind === 'sms' && (
                                        <span className="tpl-cost">
                                            {template.parts} part
                                            {template.parts === 1
                                                ? ''
                                                : 's'}{' '}
                                            per message
                                        </span>
                                    )}
                                </div>

                                <button
                                    type="button"
                                    className="admin-table-icon-btn"
                                    onClick={() => open(template)}
                                    title={`Edit ${template.name}`}
                                    aria-label={`Edit ${template.name}`}
                                >
                                    <Save size={14} />
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}

            <Modal
                isOpen={Boolean(editing)}
                onClose={close}
                title={editing ? `Edit — ${editing.name}` : ''}
                maxWidth="820px"
            >
                {editing && (
                    <div className="tpl-editor">
                        {kind === 'email' && (
                            <FormInput
                                label="Subject"
                                name="subject"
                                value={draft.subject}
                                onChange={(e) =>
                                    setDraft((d) => ({
                                        ...d,
                                        subject: e.target.value,
                                    }))
                                }
                            />
                        )}

                        {variables.length > 0 && (
                            <div className="tpl-vars">
                                <span className="admin-field-hint">
                                    Click to copy — these are filled in when the
                                    message is sent.
                                </span>
                                <div className="tpl-var-chips">
                                    {variables.map((name) => (
                                        <button
                                            key={name}
                                            type="button"
                                            className="tpl-var-chip"
                                            onClick={() => {
                                                navigator.clipboard
                                                    ?.writeText(`{${name}}`)
                                                    .catch(() => {});
                                                toast.success(
                                                    `{${name}} copied.`,
                                                    'Copied',
                                                );
                                            }}
                                        >
                                            {`{${name}}`}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {kind === 'email' ? (
                            <RichTextEditor
                                value={draft.body}
                                onChange={(html) =>
                                    setDraft((d) => ({ ...d, body: html }))
                                }
                            />
                        ) : (
                            <>
                                <textarea
                                    className="tpl-sms-box"
                                    value={draft.body}
                                    rows={5}
                                    aria-label="Message"
                                    onChange={(e) =>
                                        setDraft((d) => ({
                                            ...d,
                                            body: e.target.value,
                                        }))
                                    }
                                />
                                {cost && (
                                    <p
                                        className={`tpl-meter ${cost.parts > 1 ? 'is-over' : ''}`}
                                    >
                                        {cost.length} characters ·{' '}
                                        <strong>
                                            {cost.parts} part
                                            {cost.parts === 1 ? '' : 's'}
                                        </strong>{' '}
                                        {cost.unicode
                                            ? '— Bengali, so 70 characters per part'
                                            : '— plain text, so 160 characters per part'}
                                    </p>
                                )}
                            </>
                        )}

                        {/* Labelled, and on a line of its own, the same
                            shape as the SMTP test in Settings. Wedged between
                            the buttons it read as an orphan box, and the
                            field's own bottom margin left it floating above
                            everything beside it. */}
                        <div className="admin-test-email-row">
                            <FormInput
                                label={
                                    kind === 'email'
                                        ? 'Send a test email to'
                                        : 'Send a test SMS to'
                                }
                                name="test_to"
                                type={kind === 'email' ? 'email' : 'tel'}
                                placeholder={
                                    kind === 'email'
                                        ? 'you@example.com'
                                        : '01XXXXXXXXX'
                                }
                                value={testTo}
                                onChange={(e) => setTestTo(e.target.value)}
                            />
                            <Button
                                variant="secondary"
                                icon={Send}
                                loading={sending}
                                disabled={sending || !testTo.trim()}
                                onClick={sendTest}
                            >
                                {sending ? 'Sending…' : 'Send test'}
                            </Button>
                        </div>

                        <div className="tpl-actions">
                            <Button
                                variant="outline"
                                icon={Eye}
                                onClick={showPreview}
                            >
                                Preview
                            </Button>
                            <Button
                                variant="primary"
                                icon={Save}
                                loading={saving}
                                onClick={save}
                            >
                                Save
                            </Button>
                        </div>

                        {preview && (
                            <div className="tpl-preview">
                                <span className="admin-field-hint">
                                    As it will arrive, with example details
                                    filled in.
                                </span>

                                {kind === 'email' ? (
                                    <>
                                        <p className="tpl-preview-subject">
                                            <strong>Subject:</strong>{' '}
                                            {preview.subject}
                                        </p>
                                        <iframe
                                            title="Email preview"
                                            className="tpl-preview-frame"
                                            srcDoc={preview.html}
                                        />
                                    </>
                                ) : (
                                    <>
                                        <p className="tpl-preview-sms">
                                            {preview.body}
                                        </p>
                                        <p className="admin-field-hint">
                                            {preview.characters} characters ·{' '}
                                            {preview.parts} part
                                            {preview.parts === 1 ? '' : 's'}
                                        </p>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </Modal>
        </AdminLayout>
    );
}
