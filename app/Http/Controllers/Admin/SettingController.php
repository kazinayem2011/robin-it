<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsUpdateRequest;
use App\Http\Requests\Admin\TestEmailRequest;
use App\Mail\TestConfigurationMail;
use App\Models\SiteSetting;
use App\Services\SmsService;
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
        /*
         * Credentials never travel back to the browser; the form is told
         * whether one is saved instead.
         *
         * The SMTP password was already held back and the two SMS credentials
         * were not, which was an oversight rather than a decision — a gateway
         * token spends real money and puts messages out under the shop's name.
         */
        $withheld = array_merge(['mail_password'], SmsService::SECRET_KEYS);

        $settings = SiteSetting::all()
            ->reject(fn ($setting) => in_array($setting->key, $withheld, true))
            ->values();

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'mailPasswordSet' => MailSettings::isPasswordSet(),
            'smsSecretsSet' => collect(SmsService::SECRET_KEYS)
                ->mapWithKeys(fn ($key) => [$key => SmsService::isSecretSet($key)]),
            // Shipped rather than repeated in the page, so which messages
            // exist and which are on out of the box is decided in one place.
            'smsEvents' => collect(SmsService::EVENTS)
                ->map(fn ($event, $key) => [
                    'key' => 'sms_on_'.$key,
                    'label' => $event['label'],
                    'hint' => $event['hint'],
                    'default' => $event['default'],
                ])
                ->values(),
        ]);
    }

    public function update(SettingsUpdateRequest $request): JsonResponse
    {
        $settings = $request->settings();

        foreach ($settings as $key => $value) {
            /*
             * Credentials are encrypted at rest, and an empty submission means
             * "leave it as it is" rather than "clear it" — the form never
             * receives the current value, so a blank field is the absence of a
             * change, not an instruction to wipe a working gateway.
             */
            if ($key === 'mail_password') {
                if ($value === '') {
                    continue;
                }

                $value = MailSettings::encryptPassword($value);
            }

            if (in_array($key, SmsService::SECRET_KEYS, true)) {
                if ($value === '') {
                    continue;
                }

                $value = SmsService::encryptSecret($value);
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
