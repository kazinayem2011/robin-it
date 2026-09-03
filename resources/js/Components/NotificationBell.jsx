import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    Bell,
    ShoppingCart,
    MessageSquare,
    HelpCircle,
    PackageMinus,
    CheckCheck,
} from 'lucide-react';
import axiosInstance from '../services/axiosInstance';
import { API_ENDPOINTS, ROUTES } from '../constants/endpoints';
import { payloadFrom } from '../utils/apiPayload';
import { onUserNotification } from '../echo';
import './NotificationBell.css';

/**
 * What the shop wants you to know.
 *
 * Two sources, on purpose. The list is fetched, so anything that happened
 * while the tab was shut is still here on the next visit; the socket pushes
 * new ones in as they land, so somebody watching the screen does not have to
 * reload to find out an order came in.
 *
 * When Pusher is not configured the socket half is simply absent — the bell
 * shows what it fetched and nothing breaks.
 */

const ICONS = {
    order: ShoppingCart,
    question: HelpCircle,
    message: MessageSquare,
    stock: PackageMinus,
};

export default function NotificationBell({ userId }) {
    const [items, setItems] = useState([]);
    const [unread, setUnread] = useState(0);
    const [open, setOpen] = useState(false);
    const boxRef = useRef(null);

    const load = useCallback(async () => {
        try {
            const res = await axiosInstance.get(API_ENDPOINTS.NOTIFICATIONS);
            const payload = payloadFrom(res);

            setItems(payload.notifications ?? []);
            setUnread(payload.unread ?? 0);
        } catch {
            /* A bell that cannot load is not worth an error message over the
               page somebody is actually working on. */
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    // Live, when the socket is available.
    useEffect(() => onUserNotification(userId, () => load()), [userId, load]);

    useEffect(() => {
        const away = (e) => {
            if (boxRef.current && !boxRef.current.contains(e.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', away);

        return () => document.removeEventListener('mousedown', away);
    }, []);

    const openItem = async (item) => {
        setOpen(false);

        if (!item.read) {
            try {
                await axiosInstance.post(
                    API_ENDPOINTS.NOTIFICATION_READ(item.id),
                );
                setUnread((n) => Math.max(0, n - 1));
            } catch {
                /* Following the link matters more than the tick. */
            }
        }

        if (item.url) router.visit(item.url);
    };

    const markAll = async () => {
        try {
            await axiosInstance.post(API_ENDPOINTS.NOTIFICATIONS_READ_ALL);
            setUnread(0);
            setItems((list) => list.map((i) => ({ ...i, read: true })));
        } catch {
            /* Nothing to say — the count will be right on the next load. */
        }
    };

    return (
        <div className="notif" ref={boxRef}>
            <button
                type="button"
                className="notif-trigger"
                onClick={() => setOpen((o) => !o)}
                aria-label={
                    unread > 0
                        ? `Notifications, ${unread} unread`
                        : 'Notifications'
                }
                aria-expanded={open}
            >
                <Bell size={18} />
                {unread > 0 && (
                    <span className="notif-count">
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div
                    className="notif-panel"
                    role="dialog"
                    aria-label="Notifications"
                >
                    <div className="notif-head">
                        <strong>Notifications</strong>
                        {unread > 0 && (
                            <button type="button" onClick={markAll}>
                                <CheckCheck size={13} /> Mark all read
                            </button>
                        )}
                    </div>

                    {items.length === 0 ? (
                        <p className="notif-empty">Nothing yet.</p>
                    ) : (
                        <ul className="notif-list">
                            {items.map((item) => {
                                const Icon = ICONS[item.icon] || Bell;

                                return (
                                    <li key={item.id}>
                                        <button
                                            type="button"
                                            className={`notif-item ${item.read ? '' : 'is-unread'}`}
                                            onClick={() => openItem(item)}
                                        >
                                            <span className="notif-icon">
                                                <Icon size={15} />
                                            </span>
                                            <span className="notif-text">
                                                <strong>{item.title}</strong>
                                                <span>{item.body}</span>
                                                <em>{item.at}</em>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {/*
                     * The way out of a dropdown that holds twenty rows and no
                     * history. Always offered, including when the panel is
                     * empty: "nothing yet" is a fine answer to have, and the
                     * page is where you go to check it against last week.
                     */}
                    <Link
                        href={ROUTES.NOTIFICATIONS}
                        className="notif-see-all"
                        onClick={() => setOpen(false)}
                    >
                        See all notifications
                    </Link>
                </div>
            )}
        </div>
    );
}
