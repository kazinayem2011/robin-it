<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Subscriber;
use App\Services\CampaignService;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send one batch of a campaign.
 *
 * Queued and in batches, because the obvious version — loop over five thousand
 * customers inside the request that pressed the button — times out somewhere in
 * the middle with no record of how far it got, and the only way to find out is
 * to ask a customer whether they received it.
 *
 * Each recipient is marked before the next is attempted, so a worker that dies
 * loses at most the one it was holding. Nothing here retries automatically: a
 * failed send is recorded with its reason and left for somebody to look at,
 * because retrying a blast is how people receive it twice.
 */
class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<int, int>  $recipientIds
     */
    public function __construct(
        public int $campaignId,
        public array $recipientIds,
    ) {}

    public function handle(CampaignService $campaigns, SmsService $sms): void
    {
        $campaign = Campaign::find($this->campaignId);

        if (! $campaign) {
            return;
        }

        $recipients = CampaignRecipient::whereIn('id', $this->recipientIds)
            // Only what has not been dealt with. A batch re-run after a
            // failure must not send a second copy to the people it reached.
            ->pending()
            ->get();

        foreach ($recipients as $recipient) {
            $this->deliver($campaign, $recipient, $campaigns, $sms);
        }

        $campaigns->refreshCounts($campaign->fresh());
    }

    private function deliver(
        Campaign $campaign,
        CampaignRecipient $recipient,
        CampaignService $campaigns,
        SmsService $sms,
    ): void {
        try {
            $sent = $recipient->channel === Campaign::SMS
                ? $sms->send($recipient->contact, $campaigns->smsBody($campaign, $recipient->name))
                : $this->email($campaign, $recipient, $campaigns);

            $recipient->update($sent
                ? ['status' => CampaignRecipient::SENT, 'sent_at' => now()]
                : ['status' => CampaignRecipient::FAILED, 'error' => 'The gateway would not take it.']);
        } catch (\Throwable $e) {
            /*
             * One bad address must not stop the other four thousand. The
             * reason is kept against the row so somebody can see afterwards
             * whether it was one typo or the whole mail server.
             */
            Log::warning("Campaign {$campaign->id} could not reach {$recipient->contact}: {$e->getMessage()}");

            $recipient->update([
                'status' => CampaignRecipient::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    private function email(Campaign $campaign, CampaignRecipient $recipient, CampaignService $campaigns): bool
    {
        /*
         * The way out, found for this address at the moment of sending.
         *
         * Every marketing email must carry one. A customer who has never
         * joined the mailing list has no token yet, so one is made — which
         * also means their unsubscribe is recorded in the same place as
         * everybody else's, and honoured next time whichever list they were
         * reached through.
         */
        $subscriber = Subscriber::firstOrCreate(
            ['email' => $recipient->contact],
            [
                'name' => $recipient->name,
                'status' => Subscriber::SUBSCRIBED,
                'token' => Subscriber::newToken(),
                'source' => 'campaign',
                'subscribed_at' => now(),
            ]
        );

        Mail::to($recipient->contact)->send(new CampaignMail(
            $campaign,
            $campaigns->personalise($campaign->body, $recipient->name),
            $subscriber->unsubscribeUrl(),
        ));

        return true;
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Campaign batch {$this->campaignId} failed: {$e->getMessage()}");

        Campaign::whereKey($this->campaignId)->update(['status' => Campaign::FAILED]);
    }
}
