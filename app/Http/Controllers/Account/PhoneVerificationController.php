<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Confirming the mobile number on an account that already exists.
 *
 * Registering by mobile confirms the number on the way in and resetting a
 * password by mobile confirms it again, so the only customer left unable to was
 * the one who signed up with an email and added a number later. Their account
 * showed a number nobody had ever proved, and there was no flow that could
 * change that.
 *
 * The number is never taken from the request. It is read off the signed-in
 * account, so this cannot be used to send a code to an arbitrary number, and
 * the code that comes back can only confirm the number it was sent to.
 */
class PhoneVerificationController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Send a code to the number already on this account.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        $phone = (string) $user->phone;

        if ($phone === '') {
            throw ValidationException::withMessages([
                'phone' => 'Add a mobile number to your profile first.',
            ]);
        }

        if ($user->phone_verified_at) {
            return $this->successResponse(
                ['already_verified' => true],
                'That number is already confirmed.'
            );
        }

        if (! $this->otp->available()) {
            throw ValidationException::withMessages([
                'phone' => 'Codes cannot be sent right now. Please try again later.',
            ]);
        }

        $result = $this->otp->issue($phone, OtpCode::PURPOSE_VERIFY_PHONE, $request->ip());

        return $this->successResponse(
            ['resend_in' => $result['resend_in']],
            'We have sent a code to '.$phone.'.'
        );
    }

    /**
     * Confirm the number with the code that was sent to it.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(
            ['code' => ['required', 'string']],
            ['code.required' => 'Enter the code we sent you.']
        );

        $user = $request->user();
        $phone = (string) $user->phone;

        if ($phone === '') {
            throw ValidationException::withMessages([
                'code' => 'Add a mobile number to your profile first.',
            ]);
        }

        // Throws a ValidationException on a wrong, expired or spent code, which
        // is what puts the message under the field.
        $this->otp->verify($phone, OtpCode::PURPOSE_VERIFY_PHONE, $request->string('code'));

        /*
         * Only if it is still unconfirmed. Re-stamping would move the date
         * every time somebody pressed the button, and when the number was
         * confirmed is worth keeping.
         */
        if (! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return $this->successResponse(
            ['phone_verified_at' => $user->phone_verified_at],
            'Your mobile number is confirmed.'
        );
    }
}
