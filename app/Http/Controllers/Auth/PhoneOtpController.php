<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Sending the codes that prove somebody holds a number.
 *
 * Two flows come through here and they leak different things, so they are kept
 * apart rather than sharing one endpoint with a purpose parameter:
 *
 *   Sign-up needs the number to be free. Saying "already registered" is fine —
 *   the registration form has to say it anyway, or people cannot be told why
 *   they are stuck.
 *
 *   Reset needs the number to have an account, and must not say so. Answering
 *   differently for a number that exists turns this into a way to ask "does
 *   this person shop here", which is a list worth stealing.
 */
class PhoneOtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * A code to confirm a number at sign-up.
     */
    public function forRegistration(Request $request): JsonResponse
    {
        PhoneHelper::canonicalise($request, 'phone');

        $request->validate([
            'phone' => ['required', 'string', PhoneHelper::RULE, 'unique:'.User::class.',phone'],
        ], [
            'phone.regex' => PhoneHelper::MESSAGE,
            'phone.unique' => 'This mobile number is already registered. Sign in instead.',
        ]);

        $this->ensureCodesCanBeSent();

        $result = $this->otp->issue(
            $request->string('phone'),
            OtpCode::PURPOSE_REGISTER,
            $request->ip()
        );

        return $this->successResponse(
            ['resend_in' => $result['resend_in']],
            'We have sent a code to '.$request->string('phone').'.'
        );
    }

    /**
     * A code to get back into an account.
     */
    public function forPasswordReset(Request $request): JsonResponse
    {
        PhoneHelper::canonicalise($request, 'phone');

        $request->validate(
            ['phone' => ['required', 'string', PhoneHelper::RULE]],
            ['phone.regex' => PhoneHelper::MESSAGE]
        );

        $this->ensureCodesCanBeSent();

        $phone = $request->string('phone')->toString();

        /*
         * Only actually sent to an account that exists, but the answer is the
         * same either way.
         *
         * Two reasons, and the second is the one that bites: a different reply
         * would enumerate the customer list, and sending regardless would let
         * anybody spend the shop's SMS credit on any number in the country,
         * one text at a time, for as long as they cared to.
         */
        if (User::where('phone', $phone)->exists()) {
            $this->otp->issue($phone, OtpCode::PURPOSE_PASSWORD_RESET, $request->ip());
        }

        return $this->successResponse(
            ['resend_in' => OtpService::RESEND_SECONDS],
            'If that number has an account, we have sent it a code.'
        );
    }

    /**
     * Refuse early rather than sending a customer to a screen asking for a code
     * that can never arrive.
     */
    private function ensureCodesCanBeSent(): void
    {
        if (! $this->otp->available()) {
            throw ValidationException::withMessages([
                'phone' => 'Codes cannot be sent right now. Please try again later.',
            ]);
        }
    }
}
