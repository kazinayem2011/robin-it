import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
    Eye,
    Pencil,
    Mail,
    MessageSquare,
    ShoppingBag,
    Ticket,
    Sparkles,
    AlertTriangle,
    Search,
} from 'lucide-react';
import Modal from '@/Components/Modal';
import Button from '@/Components/Button';
import FormInput from '@/Components/FormInput';
import FormSelect from '@/Components/FormSelect';
import { toast } from '@/Components/Toast';
import { adminService } from '@/services';
import { formatBdt } from '@/utils/formatters';

const BLANK = {
    title: '',
    subject: '',
    body: '',
    channel: 'email',
    audience: 'subscribers',
};

/**
 * Writing a campaign.
 *
 * Two things make this more than a textarea.
 *
 * A promotion is about a product or a code, and typing "RTX 4090, Tk 2,45,000,
 * was Tk 2,60,000" by hand is how a campaign goes out with last month's price
 * on it. Picking one drops a token in, and the price is read at send time from
 * the same row the shop sells from.
 *
 * And nobody should send to several thousand people without seeing what lands.
 * The preview is rendered by the server — the same code that does the sending —
 * because a preview built from a second implementation in the browser is a
 * preview of that second implementation, and the first thing it stops matching
 * is the thing worth checking.
 */
export default function CampaignComposer({
    open,
    campaign = null,
    channels = {},
    audiences = {},
    onClose,
    onSaved,
}) {
    const [form, setForm] = useState(BLANK);
    const [preview, setPreview] = useState(null);
    const [showing, setShowing] = useState(false);
    const [checking, setChecking] = useState(false);
    const [saving, setSaving] = useState(false);

    const [search, setSearch] = useState('');
    const [picks, setPicks] = useState({ products: [], coupons: [] });
    const [dealProduct, setDealProduct] = useState(null);
    const [dealCoupon, setDealCoupon] = useState(null);

    const bodyRef = useRef(null);
    const readOnly = Boolean(campaign && campaign.status !== 'draft');

    useEffect(() => {
        if (!open) return;

        setForm(
            campaign
                ? {
                      title: campaign.title ?? '',
                      subject: campaign.subject ?? '',
                      body: campaign.body ?? '',
                      channel: campaign.channel ?? 'email',
                      audience: campaign.audience ?? 'subscribers',
                  }
                : BLANK,
        );
        setPreview(null);
        setShowing(Boolean(campaign && campaign.status !== 'draft'));
        setDealProduct(null);
        setDealCoupon(null);
        setSearch('');
    }, [open, campaign]);

    const loadPicks = useCallback(async (term) => {
        try {
            const res = await adminService.getCampaignPickers({ search: term });
            setPicks(res?.data ?? res ?? { products: [], coupons: [] });
        } catch {
            setPicks({ products: [], coupons: [] });
        }
    }, []);

    useEffect(() => {
        if (!open || readOnly) return undefined;

        // Debounced, or every keystroke is a request.
        const t = setTimeout(() => loadPicks(search), 250);
        return () => clearTimeout(t);
    }, [open, readOnly, search, loadPicks]);

    const set = (field) => (e) => {
        setForm((prev) => ({ ...prev, [field]: e.target.value }));
        // Any edit makes the last preview a lie; showing a stale one beside a
        // changed message is worse than showing none.
        setPreview(null);
    };

    /**
     * Drop text where the cursor is, not at the end.
     *
     * Somebody writing a paragraph and picking a product means it there — the
     * whole point of choosing the moment to insert.
     */
    const insert = (text) => {
        const box = bodyRef.current?.querySelector('textarea');

        if (!box) {
            setForm((prev) => ({ ...prev, body: prev.body + text }));
            setPreview(null);
            return;
        }

        const { selectionStart: from, selectionEnd: to } = box;

        setForm((prev) => ({
            ...prev,
            body: prev.body.slice(0, from) + text + prev.body.slice(to),
        }));
        setPreview(null);

        // Put the cursor after what was just inserted, on the next tick — the
        // value has not been written back to the DOM yet.
        setTimeout(() => {
            box.focus();
            box.setSelectionRange(from + text.length, from + text.length);
        }, 0);
    };

    const addProduct = (product) => {
        insert(`\n[[product:${product.slug}]]\n`);
        toast.success(`${product.name} added.`);
    };

    const addCoupon = (coupon) => {
        insert(`\n[[coupon:${coupon.code}]]\n`);
        toast.success(`Code ${coupon.code} added.`);
    };

    const addDeal = () => {
        insert(`\n[[deal:${dealProduct.slug}:${dealCoupon.code}]]\n`);
        toast.success(
            `${dealProduct.name} with ${dealCoupon.code} added — the price after the code is worked out when it sends.`,
        );
        setDealProduct(null);
        setDealCoupon(null);
    };

    const check = async () => {
        setChecking(true);

        try {
            const res = await adminService.previewCampaign(form);
            setPreview(res?.data ?? res);
            setShowing(true);
        } catch (err) {
            toast.error(err?.message || 'Could not work that out.');
        } finally {
            setChecking(false);
        }
    };

    const save = async () => {
        setSaving(true);

        try {
            const res = campaign
                ? await adminService.updateCampaign(campaign.id, form)
                : await adminService.createCampaign(form);
            toast.success(res?.message || 'Saved as a draft.');
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
            title={
                readOnly
                    ? campaign.title
                    : campaign
                      ? 'Edit campaign'
                      : 'Write a campaign'
            }
            maxWidth="960px"
            footer={
                readOnly ? (
                    <Button variant="secondary" onClick={onClose}>
                        Close
                    </Button>
                ) : (
                    <>
                        <Button variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            variant="secondary"
                            icon={showing && preview ? Pencil : Eye}
                            onClick={
                                showing && preview
                                    ? () => setShowing(false)
                                    : check
                            }
                            loading={checking}
                            disabled={!complete}
                        >
                            {showing && preview ? 'Back to writing' : 'Preview'}
                        </Button>
                        <Button
                            variant="primary"
                            onClick={save}
                            loading={saving}
                            disabled={!complete}
                        >
                            {campaign ? 'Save changes' : 'Save as draft'}
                        </Button>
                    </>
                )
            }
        >
            <div
                className={`cmp-composer ${showing && preview ? 'is-previewing' : ''}`}
            >
                <div className="cmp-main">
                    {showing && preview ? (
                        <PreviewPane
                            form={form}
                            preview={preview}
                            channels={channels}
                        />
                    ) : (
                        <>
                            <FormInput
                                label="Name it"
                                name="cmp_title"
                                required
                                value={form.title}
                                onChange={set('title')}
                                placeholder="For your own list — nobody else sees this"
                                disabled={readOnly}
                            />

                            <div className="cmp-row">
                                <FormSelect
                                    label="Send by"
                                    name="cmp_channel"
                                    required
                                    value={form.channel}
                                    onChange={set('channel')}
                                    disabled={readOnly}
                                    options={Object.entries(channels).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                />
                                <FormSelect
                                    label="Who to"
                                    name="cmp_audience"
                                    required
                                    value={form.audience}
                                    onChange={set('audience')}
                                    disabled={readOnly}
                                    options={Object.entries(audiences).map(
                                        ([value, label]) => ({ value, label }),
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
                                    disabled={readOnly}
                                />
                            )}

                            <div ref={bodyRef}>
                                <FormInput
                                    label="Message"
                                    name="cmp_body"
                                    type="textarea"
                                    rows={10}
                                    required
                                    value={form.body}
                                    onChange={set('body')}
                                    disabled={readOnly}
                                    placeholder="Write it as you would say it. Put {name} where their name should go."
                                    helperText="Pick a product or a code on the right to drop it in — prices are read when it sends, not now."
                                />
                            </div>
                        </>
                    )}
                </div>

                {!readOnly && !(showing && preview) && (
                    <aside className="cmp-picker">
                        <div className="cmp-picker-search">
                            <Search size={14} />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Find a product or code…"
                            />
                        </div>

                        {/*
                         * A product and a code together: the price after the
                         * code is worked out at send time, so it cannot be
                         * wrong by the time it lands.
                         */}
                        {(dealProduct || dealCoupon) && (
                            <div className="cmp-deal">
                                <p>
                                    <Sparkles size={13} /> Build one offer
                                </p>
                                <span className={dealProduct ? 'is-set' : ''}>
                                    {dealProduct?.name ?? 'Pick a product'}
                                </span>
                                <span className={dealCoupon ? 'is-set' : ''}>
                                    {dealCoupon?.code ?? 'Pick a code'}
                                </span>
                                <Button
                                    size="sm"
                                    variant="primary"
                                    disabled={!dealProduct || !dealCoupon}
                                    onClick={addDeal}
                                >
                                    Add the offer
                                </Button>
                            </div>
                        )}

                        <h5>
                            <ShoppingBag size={13} /> Products
                        </h5>
                        <ul>
                            {picks.products.map((p) => (
                                <li key={p.id}>
                                    <button
                                        type="button"
                                        onClick={() => addProduct(p)}
                                    >
                                        <span>{p.name}</span>
                                        <small>
                                            {p.discount_price ? (
                                                <>
                                                    <s>{formatBdt(p.price)}</s>{' '}
                                                    {formatBdt(
                                                        p.discount_price,
                                                    )}
                                                </>
                                            ) : (
                                                formatBdt(p.price)
                                            )}
                                        </small>
                                    </button>
                                    <button
                                        type="button"
                                        className={`cmp-pin ${dealProduct?.id === p.id ? 'is-on' : ''}`}
                                        title="Use in an offer"
                                        onClick={() =>
                                            setDealProduct(
                                                dealProduct?.id === p.id
                                                    ? null
                                                    : p,
                                            )
                                        }
                                    >
                                        <Sparkles size={12} />
                                    </button>
                                </li>
                            ))}
                            {picks.products.length === 0 && (
                                <li className="cmp-none">No products found</li>
                            )}
                        </ul>

                        <h5>
                            <Ticket size={13} /> Codes
                        </h5>
                        <ul>
                            {picks.coupons.map((c) => (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        onClick={() => addCoupon(c)}
                                    >
                                        <span>{c.code}</span>
                                        <small>
                                            {c.discount_type === 'percent'
                                                ? `${Number(c.discount_value)}% off`
                                                : `${formatBdt(c.discount_value)} off`}
                                        </small>
                                    </button>
                                    <button
                                        type="button"
                                        className={`cmp-pin ${dealCoupon?.id === c.id ? 'is-on' : ''}`}
                                        title="Use in an offer"
                                        onClick={() =>
                                            setDealCoupon(
                                                dealCoupon?.id === c.id
                                                    ? null
                                                    : c,
                                            )
                                        }
                                    >
                                        <Sparkles size={12} />
                                    </button>
                                </li>
                            ))}
                            {picks.coupons.length === 0 && (
                                <li className="cmp-none">No codes running</li>
                            )}
                        </ul>
                    </aside>
                )}
            </div>
        </Modal>
    );
}

/**
 * What actually lands, rendered by the server that will send it.
 *
 * Both halves are shown for a campaign going out on both channels, because
 * they are genuinely different messages — the email carries the shop's markup
 * and the text is stripped to what a gateway bills for.
 */
function PreviewPane({ form, preview }) {
    const showsEmail = form.channel === 'email' || form.channel === 'both';
    const showsSms = form.channel === 'sms' || form.channel === 'both';

    return (
        <div className="cmp-preview">
            <div className="cmp-reach">
                <strong>{preview.people}</strong> people
                {preview.emails > 0 && (
                    <>
                        {' · '}
                        <strong>{preview.emails}</strong> emails
                    </>
                )}
                {preview.texts > 0 && (
                    <>
                        {' · '}
                        <strong>{preview.texts}</strong> texts, billed as{' '}
                        <strong>{preview.sms_parts}</strong> parts
                    </>
                )}
            </div>

            {/*
             * A product delisted since writing would go out as a sentence with
             * a hole in it, to everybody. Sending is refused, but saying so
             * here is what lets somebody fix it.
             */}
            {preview.missing?.length > 0 && (
                <p className="cmp-warning">
                    <AlertTriangle size={14} />
                    This points at something that is no longer available:{' '}
                    {preview.missing.join(', ')}. It cannot be sent until that
                    is changed.
                </p>
            )}

            {preview.unicode && (
                <p className="cmp-warning">
                    <AlertTriangle size={14} />
                    This text is not plain English, so only 70 characters fit
                    per part instead of 160 — it costs about twice as much.
                    Usually a dash, a curly quote or an emoji pasted in from
                    somewhere else.
                </p>
            )}

            {showsEmail && (
                <section>
                    <h5>
                        <Mail size={13} /> The email
                    </h5>
                    <div className="cmp-email-frame">
                        <div className="cmp-email-subject">
                            {form.subject || form.title}
                        </div>
                        <div
                            className="cmp-email-body"
                            dangerouslySetInnerHTML={{ __html: preview.html }}
                        />
                    </div>
                </section>
            )}

            {showsSms && (
                <section>
                    <h5>
                        <MessageSquare size={13} /> The text
                    </h5>
                    <pre className="cmp-sms-frame">{preview.text}</pre>
                    <p className="admin-field-hint">
                        {preview.text.length} characters ·{' '}
                        {preview.sms_parts > 0
                            ? `${Math.ceil(preview.sms_parts / Math.max(preview.texts, 1))} part${
                                  Math.ceil(
                                      preview.sms_parts /
                                          Math.max(preview.texts, 1),
                                  ) === 1
                                      ? ''
                                      : 's'
                              } each`
                            : 'nobody to send to'}
                    </p>
                </section>
            )}

            <p className="admin-field-hint">
                Shown as it would reach somebody called Rahim — {'{name}'} is
                replaced with each person&rsquo;s own name.
            </p>
        </div>
    );
}
