<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Applies the SMTP settings saved in the admin over the .env defaults.
 *
 * The Settings screen has had an "Email & SMTP Config" tab for a while, but
 * nothing ever read those values — Laravel kept using config/mail.php from .env,
 * so saving credentials there changed nothing.
 */
class MailSettings
{
    /** Settings that make up an SMTP connection. */
    public const KEYS = [
        'mail_mailer',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    /**
     * Push saved settings into the runtime mail config.
     *
     * Does nothing when no host has been configured, so a fresh install keeps
     * whatever .env provides.
     */
    public static function apply(): void
    {
        // Never let stored credentials hijack the array transport the test
        // suite relies on, or send real mail from a test run.
        if (app()->runningUnitTests()) {
            return;
        }

        try {
            $host = SiteSetting::get('mail_host');

            if (blank($host)) {
                return;
            }

            $mailer = SiteSetting::get('mail_mailer', 'smtp') ?: 'smtp';

            config([
                'mail.default' => $mailer,
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => (int) (SiteSetting::get('mail_port', 587) ?: 587),
                'mail.mailers.smtp.username' => SiteSetting::get('mail_username') ?: null,
                'mail.mailers.smtp.password' => self::password(),
                'mail.mailers.smtp.encryption' => SiteSetting::get('mail_encryption', 'tls') ?: null,
            ]);

            if ($from = SiteSetting::get('mail_from_address')) {
                config(['mail.from.address' => $from]);
            }

            if ($fromName = SiteSetting::get('mail_from_name')) {
                config(['mail.from.name' => $fromName]);
            }
        } catch (\Throwable $e) {
            // A broken settings row must never take the whole site down;
            // fall back to .env and record why.
            Log::warning('Could not apply mail settings from the database: '.$e->getMessage());
        }
    }

    /**
     * SMTP passwords are encrypted at rest — the settings table is readable by
     * anything with database access, and this is a live credential.
     */
    public static function password(): ?string
    {
        $stored = SiteSetting::get('mail_password');

        if (blank($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            // Written before encryption was introduced, or by hand.
            return $stored;
        }
    }

    /** Encrypt before saving. */
    public static function encryptPassword(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    /**
     * What the admin form should show: never the password itself.
     */
    public static function isPasswordSet(): bool
    {
        return filled(SiteSetting::get('mail_password'));
    }
}
