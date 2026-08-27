<?php

namespace App\Mail;

use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Support\BrandDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The shop's answer to something a customer wrote in.
 *
 * Not queued, unlike the order mail: whoever pressed Send is looking at the
 * screen and should be told there and then whether it went out. ContactService
 * records the reply first and marks whether the mail succeeded, so a mail
 * server that is down loses the delivery, never the answer.
 */
class ContactReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContactMessage $contactMessage,
        public ContactReply $reply,
    ) {}

    public function build()
    {
        $brand = BrandDetails::all();

        return $this->subject('Re: '.$this->contactMessage->subject)
            ->replyTo($brand['email'] ?? config('mail.from.address'))
            ->view('emails.support.contact-reply')
            ->text('emails.text.support.contact-reply');
    }
}
