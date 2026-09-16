<?php

namespace App\Mail;

use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Support\BrandDetails;
use App\Support\MailTemplate;
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

        $written = MailTemplate::for('contact_reply', [
            'shop_name' => $brand['name'],
            'customer_name' => $this->contactMessage->name,
            'enquiry_subject' => $this->contactMessage->subject,
            /*
             * The same escaping the Blade view gives it. A reply is typed in a
             * plain box by whoever answered, so it is text, not markup — and
             * the template drops it into HTML.
             */
            'reply_body' => nl2br(e((string) $this->reply->body)),
        ]);

        if ($written) {
            return $this->subject($written['subject'])
                ->replyTo($brand['email'] ?? config('mail.from.address'))
                ->view('emails.templated', $written['data'])
                ->text('emails.templated-text', $written['data']);
        }

        return $this->subject('Re: '.$this->contactMessage->subject)
            ->replyTo($brand['email'] ?? config('mail.from.address'))
            ->view('emails.support.contact-reply')
            ->text('emails.text.support.contact-reply');
    }
}
