<?php

namespace App\Http\Requests\Auth;

use App\Helpers\PhoneHelper;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials via Email OR Bangladeshi Phone.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $loginInput = trim($this->input('login', $this->input('email', '')));
        $password = $this->input('password');
        $remember = $this->boolean('remember');

        $authenticated = false;

        // 1. Check if input is a valid email
        if (filter_var($loginInput, FILTER_VALIDATE_EMAIL)) {
            $authenticated = Auth::attempt(['email' => $loginInput, 'password' => $password], $remember);
        } else {
            // 2. Treat as BD mobile phone number using centralized PhoneHelper (SSOT)
            $normalizedPhone = PhoneHelper::normalizeBdPhone($loginInput);

            // Try authenticating by normalized phone
            $authenticated = Auth::attempt(['phone' => $normalizedPhone, 'password' => $password], $remember);

            // If failed, also try direct match with raw login input
            if (! $authenticated && $loginInput !== $normalizedPhone) {
                $authenticated = Auth::attempt(['phone' => $loginInput, 'password' => $password], $remember);
            }
        }

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'Invalid email/mobile number or password.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        $login = $this->input('login', $this->input('email', ''));

        return Str::transliterate(Str::lower($login).'|'.$this->ip());
    }
}
