<?php

namespace App\Mail;

use App\Models\User;
use App\Support\BrandDetails;
use App\Support\MailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Queued so checkout does not wait on the mail server. Requires a queue worker
 * (`php artisan queue:work`) to be running — without one, mail is written to the
 * jobs table and never sent.
 *
 * Encrypted on the queue, because for an account checkout made it carries the
 * password generated for it, and the jobs table is a database row like any
 * other — backed up, copied, read by whoever has the database.
 */
class WelcomeCustomerMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;

    /**
     * The password checkout generated, for an account it made; null for
     * somebody who registered and chose their own.
     *
     * Sent because the shop's decision is that customers always sign in with
     * a password, and without it an account made at checkout had no way back
     * in once the session ended.
     */
    public ?string $password;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, ?string $password = null)
    {
        $this->user = $user;
        $this->password = $password;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $brand = BrandDetails::all();
        $details = $this->signInDetailsHtml();

        $written = MailTemplate::for('welcome', [
            'shop_name' => $brand['name'],
            'customer_name' => $this->user->name,
            'shop_url' => url('/'),
            'sign_in_details' => $details,
        ]);

        if ($written) {
            /*
             * A shop's own wording written before {sign_in_details} existed
             * would send an account checkout made its welcome and not its
             * password — so the details go on the end when the template left
             * them out. Nobody is told they have an account and not how to
             * get into it.
             */
            if ($this->password !== null && ! str_contains($written['data']['bodyHtml'], $this->password)) {
                $written['data']['bodyHtml'] .= $details;
            }

            return $this->subject($written['subject'])
                ->view('emails.templated', $written['data'])
                ->text('emails.templated-text', $written['data']);
        }

        return $this->subject("Welcome to {$brand['name']} — {$brand['tagline']}")
            ->view('emails.auth.welcome', ['password' => $this->password])
            ->text('emails.text.auth.welcome', ['password' => $this->password]);
    }

    /**
     * What to sign in with, for an account checkout made — or nothing.
     *
     * Markup, like {order_items}, so it is supplied rather than typed: the
     * sign-in id and the password laid out where they cannot be misread.
     */
    private function signInDetailsHtml(): string
    {
        if ($this->password === null) {
            return '';
        }

        $lines = array_filter([
            $this->user->phone ? 'Mobile: '.e($this->user->phone) : null,
            $this->user->email ? 'Email: '.e($this->user->email) : null,
            'Password: <strong>'.e($this->password).'</strong>',
        ]);

        return '<p><strong>Your sign-in details</strong><br>'
            .implode('<br>', $lines)
            .'<br>Sign in with your mobile number or email and this password. '
            .'You can change it from your profile.</p>';
    }
}
