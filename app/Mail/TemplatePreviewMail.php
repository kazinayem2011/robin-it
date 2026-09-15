<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One template, sent to whoever asked to see it.
 *
 * Not queued: an admin checking their own wording needs the SMTP error back,
 * not a job that failed quietly an hour later.
 */
class TemplatePreviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Test] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.templated',
            with: [
                'title' => $this->subjectLine,
                'preheader' => null,
                'bodyHtml' => $this->bodyHtml,
            ],
        );
    }
}
