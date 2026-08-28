<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Getting back in with a text message.
 *
 * The only way to reset a password was a link sent by email. Plenty of
 * customers here sign up with an address they never open — or one typed wrong
 * — and for them a forgotten password meant the account was gone, along with
 * its order history and every warranty record attached to it. The number they
 * actually use is the one on the account.
 */
class PhonePasswordResetController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPasswordPhone', [
            'resendSeconds' => OtpService::RESEND_SECONDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PhoneHelper::canonicalise($request, 'phone');

        $request->validate([
            'phone' => ['required', 'string', PhoneHelper::RULE],
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], ['phone.regex' => PhoneHelper::MESSAGE]);

        $phone = $request->string('phone')->toString();

        /*
         * Checked before the code is spent.
         *
         * The account not existing and the code being wrong get the same
         * answer, so this cannot be used to ask whether a number shops here —
         * a code was never sent for a number with no account, so there is
         * nothing here that could match anyway.
         */
        $user = User::where('phone', $phone)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right. Ask for a new one.',
            ]);
        }

        $this->otp->verify($phone, OtpCode::PURPOSE_PASSWORD_RESET, $request->string('code'));

        $user->forceFill([
            'password' => Hash::make($request->string('password')),
            /*
             * They read a code sent to this number, which is the same proof
             * sign-up asks for. Recording it here means an older account gets
             * verified the first time it needs recovering.
             */
            'phone_verified_at' => $user->phone_verified_at ?? now(),
            // Any browser left signed in with "remember me" is not necessarily
            // the customer's; a reset is often exactly the moment it is not.
            'remember_token' => Str::random(60),
        ])->save();

        event(new PasswordReset($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('success', 'Your password has been changed.');
    }
}
