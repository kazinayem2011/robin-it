<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            /*
             * Whether the form asks for a code at all.
             *
             * Told to the page rather than assumed by it: with no SMS gateway
             * configured no code can be sent, and a form demanding one would
             * shut every new customer out of the shop.
             */
            'verifyPhone' => $this->otp->available(),
            'resendSeconds' => OtpService::RESEND_SECONDS,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // The rules judge the number, not its punctuation.
        PhoneHelper::canonicalise($request, 'phone');

        $verifying = $this->otp->available();

        /*
         * Either identifier will do, and one of them must be there.
         *
         * Signing in has always accepted an address or a mobile, and most
         * customers here have the mobile — asking for both turned away the
         * ones who only had that. required_without in both directions says
         * "one of these", and leaves either free to be omitted.
         */
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required_without:phone',
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:'.User::class,
            ],
            'phone' => [
                'required_without:email',
                'nullable',
                'string',
                PhoneHelper::RULE,
                'unique:'.User::class.',phone',
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Only asked for when a code could actually have been sent, which
            // needs a number to have sent it to.
            'code' => [$verifying && filled($request->phone) ? 'required' : 'nullable', 'string'],
        ], [
            'email.required_without' => 'Give us an email address or a mobile number so we can reach you.',
            'phone.required_without' => 'Give us a mobile number or an email address so we can reach you.',
            'phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number (e.g. 01711223344).',
            'phone.unique' => 'This mobile number is already registered with an account.',
            'code.required' => 'Enter the code we sent to your mobile.',
        ]);

        $normalizedPhone = PhoneHelper::normalizeBdPhone($request->phone);

        /*
         * The code is checked after the rest of the form, so a customer with a
         * password that is too short is told that before their code is spent
         * and they have to wait out the cooldown for another.
         */
        if ($verifying && $normalizedPhone) {
            $this->otp->verify($normalizedPhone, OtpCode::PURPOSE_REGISTER, $request->string('code'));
        }

        // `role` is guarded on the model; new sign-ups are always customers.
        $user = new User;
        $user->fill([
            'name' => $request->name,
            // Absent rather than empty: '' would occupy the unique index and
            // the second account without an address would be refused.
            'email' => $request->filled('email') ? $request->email : null,
            'phone' => $normalizedPhone ?: null,
            'password' => Hash::make($request->password),
        ]);

        // Only when a code was actually read off a text sent to it.
        if ($verifying && $normalizedPhone) {
            $user->phone_verified_at = now();
        }
        $user->assignRole(User::ROLE_CUSTOMER)->save();

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
