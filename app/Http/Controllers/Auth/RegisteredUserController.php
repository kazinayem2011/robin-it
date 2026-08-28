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

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'phone' => [
                'required',
                'string',
                PhoneHelper::RULE,
                'unique:'.User::class.',phone',
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Only asked for when a code could actually have been sent.
            'code' => [$verifying ? 'required' : 'nullable', 'string'],
        ], [
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
        if ($verifying) {
            $this->otp->verify($normalizedPhone, OtpCode::PURPOSE_REGISTER, $request->string('code'));
        }

        // `role` is guarded on the model; new sign-ups are always customers.
        $user = new User;
        $user->fill([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $normalizedPhone,
            'password' => Hash::make($request->password),
        ]);

        // Only when a code was actually read off a text sent to it.
        if ($verifying) {
            $user->phone_verified_at = now();
        }
        $user->assignRole(User::ROLE_CUSTOMER)->save();

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
