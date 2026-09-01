import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * The socket, when there is one.
 *
 * Pusher's credentials live in the environment, and a shop that has not set
 * them up yet must still work: without a key this returns null, the bell falls
 * back to what it loaded on the last page view, and nothing throws. That is
 * the difference between "instant updates are not switched on" and "the admin
 * is broken".
 */
let echo = null;

export const initEcho = () => {
    if (echo) return echo;

    const key = import.meta.env.VITE_PUSHER_APP_KEY;

    if (!key) return null;

    window.Pusher = Pusher;

    echo = new Echo({
        broadcaster: 'pusher',
        key,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        wsHost: import.meta.env.VITE_PUSHER_HOST || undefined,
        wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
        wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        // Laravel signs private channels through this route; without it a
        // private subscription fails silently and nothing ever arrives.
        authEndpoint: '/broadcasting/auth',
    });

    return echo;
};

/**
 * Listen for one user's notifications.
 *
 * Returns a function that stops listening, so a component can clean up on
 * unmount rather than stacking a fresh subscription on every render.
 */
export const onUserNotification = (userId, handler) => {
    const instance = initEcho();

    if (!instance || !userId) return () => {};

    const channel = instance.private(`App.Models.User.${userId}`);

    channel.notification(handler);

    return () => {
        try {
            instance.leave(`App.Models.User.${userId}`);
        } catch {
            /* Already gone — nothing to do. */
        }
    };
};
