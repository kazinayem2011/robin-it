<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\PhoneOtpController;
use App\Http\Controllers\Auth\PhonePasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    // Unlimited account creation is a spam tap. Signing in is already
    // throttled per email and IP inside LoginRequest; these three were not
    // limited at all.
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    // Reset requests are how an address list gets enumerated — the response
    // differs for an account that exists — and how a mailbox gets flooded.
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');

    /*
     * Codes sent to a mobile.
     *
     * Throttled harder than the forms they serve, and on the IP as well as the
     * number: OtpService caps how many codes one number can be sent, but
     * nothing there stops one machine walking through a range of numbers, and
     * every one of those attempts would be a text the shop paid for.
     */
    Route::post('otp/register', [PhoneOtpController::class, 'forRegistration'])
        ->middleware('throttle:8,10')
        ->name('otp.register');

    Route::post('otp/password', [PhoneOtpController::class, 'forPasswordReset'])
        ->middleware('throttle:8,10')
        ->name('otp.password');

    Route::get('forgot-password/mobile', [PhonePasswordResetController::class, 'create'])
        ->name('password.phone');

    Route::post('forgot-password/mobile', [PhonePasswordResetController::class, 'store'])
        ->middleware('throttle:10,10')
        ->name('password.phone.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
