import axiosInstance from './axiosInstance';
import { ROUTES } from '../constants/endpoints';

/*
 * These are web routes rather than /api ones: they are only ever called from
 * the sign-up and password-reset forms, which already carry the session and
 * the CSRF token, and a code is a step in a browser flow rather than something
 * an API client should be able to ask for.
 *
 * Which means the shared instance's /api base has to be turned off per call.
 * Without this the request goes to /api/otp/register, which does not exist —
 * and a 404 caught by the form reads as "could not send a code", so the flow
 * simply stops with no explanation.
 */
const asWebRoute = { baseURL: '' };

/**
 * Asking the shop to text a one-time code.
 */
export const otpService = {
    /** A code to confirm a mobile number at sign-up. */
    async forRegistration(phone) {
        const response = await axiosInstance.post(
            ROUTES.OTP_REGISTER,
            { phone },
            asWebRoute,
        );
        return response?.data || response;
    },

    /**
     * A code to reset a forgotten password.
     *
     * Answers the same whether or not the number has an account — the shop
     * will not confirm who its customers are.
     */
    async forPasswordReset(phone) {
        const response = await axiosInstance.post(
            ROUTES.OTP_PASSWORD,
            { phone },
            asWebRoute,
        );
        return response?.data || response;
    },

    /**
     * A code to confirm the number already on the signed-in account.
     *
     * No number is sent: the server reads it off the account, so this cannot
     * be pointed at somebody else's handset.
     */
    async toVerifyMyNumber() {
        const response = await axiosInstance.post(
            ROUTES.PHONE_VERIFICATION_SEND,
            {},
            asWebRoute,
        );
        return response?.data || response;
    },

    /** Spend that code and mark the number confirmed. */
    async confirmMyNumber(code) {
        const response = await axiosInstance.post(
            ROUTES.PHONE_VERIFICATION_VERIFY,
            { code },
            asWebRoute,
        );
        return response?.data || response;
    },
};

export default otpService;
