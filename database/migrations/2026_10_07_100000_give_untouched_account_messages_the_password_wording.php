<?php

use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Support\MessageKeys;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The new-account text and the welcome email, reworded for a password.
 *
 * Accounts made at checkout now get a generated password, texted and emailed
 * to them, because customers always sign in with one. The saved default text
 * still said "set a password in your profile", and the password was added on
 * the end of it — "set a password… Password: x7Kp4mQa".
 *
 * Only a row the shop never edited is replaced — one whose updated time is its
 * created time and which does not already carry the new placeholder. Wording a
 * shop wrote itself is left alone; the password is still added to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = new MessageTemplateSeeder;
        $defaults = fn (string $method) => collect((fn () => $this->{$method}())->call($seeder))->keyBy('key');

        $text = $defaults('texts')->get('account_created');
        $sms = SmsTemplate::where('key', 'account_created')->first();

        if ($text && $sms && $this->untouched($sms) && ! str_contains((string) $sms->body, '{password}')) {
            $sms->forceFill([
                'body' => $text['body'],
                'hint' => $text['hint'] ?? $sms->hint,
                'variables' => MessageKeys::sms('account_created'),
            ])->saveQuietly();
        }

        $mail = $defaults('emails')->get('welcome');
        $email = EmailTemplate::where('key', 'welcome')->first();

        if ($mail && $email && $this->untouched($email) && ! str_contains((string) $email->body, '{sign_in_details}')) {
            $email->forceFill([
                'subject' => $mail['subject'] ?? $email->subject,
                'body' => $mail['body'],
                'variables' => MessageKeys::email('welcome'),
            ])->saveQuietly();
        }
    }

    /** Never edited: saved once, by the seeder, and not since. */
    private function untouched($row): bool
    {
        return $row->created_at && $row->updated_at && $row->created_at->equalTo($row->updated_at);
    }

    public function down(): void
    {
        // The old wording told customers to set a password they had been sent.
    }
};
