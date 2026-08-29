<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Support\BrandDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * One marketing email.
 *
 * Not queued itself: it is sent from SendCampaignMessage, which is already a
 * queued job working through a batch. Queueing it again would put every
 * individual email back on the queue and lose the record of how far the batch
 * had got.
 */
class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Campaign $campaign,
        public string $body,
        public string $unsubscribeUrl,
    ) {}

    public function build()
    {
        $brand = BrandDetails::all();

        return $this->subject($this->campaign->subject ?: $this->campaign->title)
            ->view('emails.marketing.campaign')
            ->text('emails.text.marketing.campaign')
            /*
             * The header mail clients use to put an unsubscribe button beside
             * the sender's name. Without it, the way to stop a shop's email is
             * the spam button — which costs the shop its deliverability for
             * everybody else on the list.
             */
            ->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$this->unsubscribeUrl}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            })
            ->with([
                'brand' => $brand,
                'body' => $this->body,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ]);
    }
}
