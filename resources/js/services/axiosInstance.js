import axios from 'axios';

/**
 * Reusable Axios instance for API calls.
 * Automatically handles CSRF tokens and common headers.
 */
const axiosInstance = axios.create({
    baseURL: '/api',
    timeout: 15000,
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Every rejection reaches the UI in the same shape, so a component can always
 * show `error.message` and trust it is a sentence written for the customer.
 */
export class ApiError extends Error {
    constructor({ message, code, status, errors, data }) {
        super(message);
        this.name = 'ApiError';
        this.code = code || 'GENERIC';
        this.status = status ?? 0;
        this.errors = errors || {};
        this.data = data ?? null;
    }

    /** First validation message for a given field, if any. */
    fieldError(field) {
        const messages = this.errors?.[field];
        return Array.isArray(messages) ? messages[0] : messages || null;
    }
}

const FALLBACK_MESSAGE =
    'Something went wrong. Please check your connection and try again.';

/**
 * Query parameters, in the shape the backend validates.
 *
 * axios turns a JS `true` into the string "true", and Laravel's `boolean` rule
 * compares strictly against [true, false, 0, 1, '0', '1'] — so "true" fails.
 * Ticking "In stock only" or "On sale" in the shop sent `in_stock=true`, got a
 * 422 back, and replaced the whole grid with "We couldn't load these
 * products". Both filters had never worked.
 *
 * Doing it here rather than at each call site means any service can pass a
 * plain boolean and have it arrive as the backend expects. Empty values are
 * dropped so an unset filter does not travel as `min_price=`.
 */
export const normaliseParams = (params) =>
    Object.fromEntries(
        Object.entries(params)
            .filter(
                ([, value]) =>
                    value !== undefined && value !== null && value !== '',
            )
            .map(([key, value]) => [
                key,
                typeof value === 'boolean' ? (value ? 1 : 0) : value,
            ]),
    );

axiosInstance.interceptors.request.use((config) => {
    if (!config.params || typeof config.params !== 'object') return config;

    config.params = normaliseParams(config.params);

    return config;
});

// Interceptor for responses
axiosInstance.interceptors.response.use(
    (response) => {
        // Return the standardized data payload from the ApiResponse trait
        return response.data;
    },
    (error) => {
        const response = error?.response;
        const envelope = response?.data;

        if (response?.status === 401) {
            // Session expired mid-action — send them to sign in and come back.
            const next = encodeURIComponent(
                window.location.pathname + window.location.search,
            );
            window.location.href = `/login?redirect=${next}`;
        }

        if (response?.status === 419) {
            // CSRF token expired; a reload issues a fresh one.
            return Promise.reject(
                new ApiError({
                    message:
                        'Your session expired. Please refresh the page and try again.',
                    code: 'SESSION_EXPIRED',
                    status: 419,
                }),
            );
        }

        return Promise.reject(
            new ApiError({
                message:
                    envelope?.message || error?.message || FALLBACK_MESSAGE,
                code: envelope?.code,
                status: response?.status,
                errors: envelope?.data?.errors,
                data: envelope?.data,
            }),
        );
    },
);

export default axiosInstance;
