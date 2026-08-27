<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsUpdateRequest;
use App\Http\Requests\Admin\TestEmailRequest;
use App\Mail\TestConfigurationMail;
use App\Models\SiteSetting;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site settings & announcement ticker.
 */
class SettingController extends Controller
{
    public function index(): Response
    {
        // Send everything except the SMTP password, which must not travel back
        // to the browser. The form shows whether one is set instead.
        $settings = SiteSetting::all()
            ->reject(fn ($setting) => $setting->key === 'mail_password')
            ->values();

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'mailPasswordSet' => MailSettings::isPasswordSet(),
        ]);
    }

    public function update(SettingsUpdateRequest $request): JsonResponse
    {
        $settings = $request->settings();

        foreach ($settings as $key => $value) {
            // The SMTP password is a live credential; encrypt it at rest. An
            // empty submission means "leave it as it is" rather than "clear it",
            // because the form never receives the current value to send back.
            if ($key === 'mail_password') {
                if ($value === '') {
                    continue;
                }

                $value = MailSettings::encryptPassword($value);
            }

            SiteSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        SiteSetting::flushCache(array_keys($settings));

        return $this->successResponse([], 'Settings saved successfully.');
    }

    /**
     * Send a test message using the SMTP settings currently saved.
     *
     * Sends synchronously and on-demand rather than through the queue, so the
     * admin gets the actual SMTP error back instead of a silent failed job.
     */
    public function sendTestEmail(TestEmailRequest $request): JsonResponse
    {
        $email = $request->validated()['email'];

        MailSettings::apply();

        try {
            Mail::mailer(config('mail.default'))
                ->to($email)
                ->sendNow(new TestConfigurationMail);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Could not send: '.$e->getMessage(),
                422,
                ApiCode::GENERIC
            );
        }

        return $this->successResponse([
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
        ], "Test email sent to {$email}. Check the inbox to confirm.");
    }
}
