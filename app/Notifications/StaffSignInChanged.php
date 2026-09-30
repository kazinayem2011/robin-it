<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\BrandDetails;
use App\Support\Roles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A staff or admin account's password, email or mobile changed. Told to the
 * owner, never to customers.
 *
 * The owner's own password changed once with nobody on the shop's side having
 * done it, and the first anyone knew was that it no longer worked. Whoever can
 * change a sign-in can take over the admin, so the owner hears about every
 * such change the moment it is saved — on the bell, by email and by text —
 * with who did it, so a change nobody made stands out.
 */
class StaffSignInChanged extends ShopNotification implements ShouldQueue
{
    /**
     * @param  array<int, string>  $changed  password, email and/or phone
     * @param  array<string, ?string>  $before  the old email and phone
     */
    public function __construct(
        public readonly User $account,
        public readonly array $changed,
        public readonly array $before,
        public readonly string $by,
        public readonly string $when,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null)
            ? ['database', 'broadcast', 'mail']
            : ['database', 'broadcast'];
    }

    /**
     * The bell at once; the email through the queue.
     *
     * Sent inline, the email held the save open while the mail server
     * answered — eighteen seconds for the second owner on a test run, with
     * the person changing their password staring at a spinner.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'broadcast' => 'sync',
            'mail' => (string) config('queue.default'),
        ];
    }

    public function who(): string
    {
        $role = Roles::all()[$this->account->role]['label'] ?? $this->account->role;

        return "{$this->account->name} ({$role})";
    }

    /** "password and email", in plain words. */
    public function what(): string
    {
        $words = array_map(fn ($f) => $f === 'phone' ? 'mobile' : $f, $this->changed);

        return count($words) > 1
            ? implode(', ', array_slice($words, 0, -1)).' and '.end($words)
            : $words[0];
    }

    /** @return array<int, string> Each change, old to new where it has one. */
    public function lines(): array
    {
        return array_map(fn ($field) => match ($field) {
            'password' => 'Password changed.',
            'email' => 'Email changed from '.($this->before['email'] ?: 'none').' to '.($this->account->email ?: 'none').'.',
            'phone' => 'Mobile changed from '.($this->before['phone'] ?: 'none').' to '.($this->account->phone ?: 'none').'.',
        }, $this->changed);
    }

    public function payload(object $notifiable): array
    {
        return [
            'kind' => 'staff.signin',
            'title' => 'Sign-in changed: '.$this->who(),
            'body' => ucfirst($this->what())." changed {$this->when}, by {$this->by}.",
            'url' => '/admin/staff',
            'icon' => 'staff',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(BrandDetails::name().': sign-in changed for '.$this->account->name)
            ->greeting('A staff sign-in was changed')
            ->line('Account: '.$this->who());

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail
            ->line("When: {$this->when}. By: {$this->by}.")
            ->line('If nobody on your side made this change, reset that account\'s password now and check who has access.')
            ->action('Open Staff', url('/admin/staff'));
    }

    /** The text, kept to one or two messages. */
    public function sms(): string
    {
        return '('.BrandDetails::name().') Sign-in changed: '.$this->who().' - '
            .$this->what().", {$this->when}, by {$this->by}. Not you? Check Admin > Staff now.";
    }
}
