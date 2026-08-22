<?php

namespace App\Mail;

use App\Support\BrandDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent from the admin to prove the saved SMTP settings work.
 *
 * Deliberately NOT queued: the admin presses a button and needs the real SMTP
 * result immediately, not a job that fails quietly a moment later.
 */
class TestConfigurationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function build()
    {
        $brand = BrandDetails::all();

        return $this->subject("Test email from {$brand['name']}")
            ->view('emails.system.test')
            ->text('emails.text.system.test');
    }
}
