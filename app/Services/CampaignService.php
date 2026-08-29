<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\BrandDetails;
use App\Support\CampaignContent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sending one message to everybody.
 *
 * The shop could reply to one person and text one customer about one order, and
 * had no way to say "the Eid sale starts Thursday" to the list it had spent a
 * year collecting.
 *
 * Three things this does that the obvious version does not.
 *
 * It asks whether each person wants to hear from the shop. An order
 * confirmation is something a customer asked for; a sale announcement is not,
 * and somebody who has left the mailing list must not receive one because they
 * also happen to have an account.
 *
 * It works out what the text messages will cost before any of them are sent.
 * One em dash in a sentence pushes the whole message into unicode and halves
 * what fits, so a careless character can double a five-thousand-message bill —
 * and that is worth seeing before pressing send, not on the invoice.
 *
 * And it writes down every recipient before sending to any of them, so a
 * campaign that stops half way can be picked up without sending a second copy
 * to everybody who already had it.
 */
class CampaignService
{
    /** Sent in batches, so one enormous list does not become one enormous job. */
    public const CHUNK = 100;

    /**
     * Who this campaign would reach.
     *
     * @return Collection<int, array{name: ?string, email: ?string, phone: ?string}>
     */
    public function audienceFor(Campaign $campaign): Collection
    {
        $people = collect();

        /*
         * Addresses that have left the list.
         *
         * Checked against customers too, not only against the list itself.
         * Somebody who unsubscribed and also has an account has said no once,
         * and being mailed anyway because the shop keeps two lists is exactly
         * the thing that makes people stop trusting an unsubscribe link.
         */
        $gone = Subscriber::where('status', Subscriber::UNSUBSCRIBED)
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->flip();

        if (in_array($campaign->audience, ['subscribers', 'all'], true)) {
            // active(), not all of them. A list that includes the people who
            // left it is not a list, it is a liability.
            Subscriber::active()->get(['name', 'email'])->each(function ($subscriber) use ($people) {
                $people->push([
                    'name' => $subscriber->name ?: null,
                    'email' => $subscriber->email,
                    // The mailing list holds addresses only.
                    'phone' => null,
                ]);
            });
        }

        if (in_array($campaign->audience, ['customers', 'all'], true)) {
            User::query()
                ->where('role', User::ROLE_CUSTOMER)
                ->where('accepts_marketing', true)
                ->where('is_active', true)
                ->get(['name', 'email', 'phone'])
                ->each(function ($user) use ($people, $gone) {
                    if (isset($gone[mb_strtolower(trim((string) $user->email))])) {
                        return;
                    }

                    $people->push([
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                    ]);
                });
        }

        return $people;
    }

    /**
     * What this campaign would reach and cost, without sending anything.
     *
     * @return array{emails: int, texts: int, sms_parts: int, people: int, unicode: bool}
     */
    public function estimate(Campaign $campaign): array
    {
        $people = $this->audienceFor($campaign);

        $emails = $campaign->sendsEmail()
            ? $people->pluck('email')->filter()->map(fn ($e) => mb_strtolower(trim($e)))->unique()->count()
            : 0;

        $texts = $campaign->sendsSms()
            ? $people->pluck('phone')->filter()->map(fn ($p) => trim($p))->unique()->count()
            : 0;

        $body = $this->smsBody($campaign);

        return [
            'emails' => $emails,
            'texts' => $texts,
            // What the gateway will actually bill: parts, not messages.
            'sms_parts' => $texts * SmsService::parts($body),
            'people' => $people
                ->map(fn ($p) => mb_strtolower(trim((string) ($p['email'] ?? $p['phone'] ?? ''))))
                ->filter()->unique()->count(),
            // The single most expensive detail, and the easiest to introduce
            // by accident with one curly quote.
            'unicode' => $texts > 0 && ! SmsService::isGsm7($body),
        ];
    }

    /**
     * Write down who is getting this, and set the queue going.
     */
    public function send(Campaign $campaign): Campaign
    {
        if ($campaign->status !== Campaign::DRAFT) {
            throw new StorefrontException(
                $campaign->status === Campaign::SENDING
                    ? 'This campaign is already going out.'
                    : 'This campaign has already been sent. Copy it into a new one instead.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        /*
         * A product delisted between writing the campaign and sending it would
         * otherwise go out as a sentence with a hole in it, to everybody.
         */
        if ($missing = CampaignContent::missing($campaign->body)) {
            throw new StorefrontException(
                'This campaign points at something that is no longer available: '
                    .implode(', ', $missing).'. Edit it before sending.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $rows = $this->recipientRows($campaign);

        if ($rows === []) {
            throw new StorefrontException(
                'Nobody in that audience can be reached on that channel. '
                    .'Check that people have opted in, and that a text campaign has customers with phone numbers.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        DB::transaction(function () use ($campaign, $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                CampaignRecipient::insert($chunk);
            }

            $estimate = $this->estimate($campaign);

            $campaign->update([
                'status' => Campaign::SENDING,
                'started_at' => now(),
                'recipient_count' => count($rows),
                'sms_parts' => $estimate['sms_parts'],
            ]);
        });

        /*
         * Dispatched after the transaction commits. A queue worker is a
         * different process and will happily pick a job up before this one has
         * committed, then fail to find the row it was given.
         */
        $campaign->recipients()->pending()->pluck('id')
            ->chunk(self::CHUNK)
            ->each(fn ($ids) => SendCampaignMessage::dispatch($campaign->id, $ids->all()));

        return $campaign->fresh();
    }

    /**
     * The rows to write, one per person per channel, deduplicated.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recipientRows(Campaign $campaign): array
    {
        $rows = [];
        $seen = [];
        $now = now();

        foreach ($this->audienceFor($campaign) as $person) {
            if ($campaign->sendsEmail() && filled($person['email'])) {
                $key = 'email:'.mb_strtolower(trim($person['email']));

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = [
                        'campaign_id' => $campaign->id,
                        'name' => $person['name'],
                        'contact' => trim($person['email']),
                        'channel' => Campaign::EMAIL,
                        'status' => CampaignRecipient::PENDING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($campaign->sendsSms() && filled($person['phone'])) {
                $key = 'sms:'.trim($person['phone']);

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = [
                        'campaign_id' => $campaign->id,
                        'name' => $person['name'],
                        'contact' => trim($person['phone']),
                        'channel' => Campaign::SMS,
                        'status' => CampaignRecipient::PENDING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * The text as a gateway will see it.
     *
     * The shop's name is put on the front rather than left to the sender ID,
     * because a promotional message from an unfamiliar short code reads as spam
     * — and it has to be counted, since those characters cost the same as any
     * other.
     */
    public function smsBody(Campaign $campaign, ?string $name = null): string
    {
        // Rendered first: the box holds "[[product:rtx-4090]]" and the gateway
        // is billed for the two lines that becomes.
        $body = CampaignContent::text($this->personalise($campaign->body, $name));

        return BrandDetails::name().': '.trim($body);
    }

    /** The body as the email will actually render it. */
    public function emailBody(Campaign $campaign, ?string $name = null): string
    {
        return CampaignContent::html($this->personalise($campaign->body, $name));
    }

    /**
     * Somebody's name where the writer asked for it.
     *
     * A blank fallback rather than "Valued Customer": a message opening "Dear
     * Valued Customer" announces itself as a circular, which is the one thing
     * a shop sending a circular does not want.
     */
    public function personalise(string $body, ?string $name = null): string
    {
        return str_replace(
            ['{name}', '{customer_name}'],
            trim((string) $name) ?: 'there',
            $body
        );
    }

    /**
     * Bring the counts on the campaign in line with the rows.
     *
     * Counted rather than incremented as it goes: a worker killed mid-batch
     * would otherwise leave the figure permanently wrong, and this is the
     * number somebody reports to their boss.
     */
    public function refreshCounts(Campaign $campaign): void
    {
        $counts = $campaign->recipients()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts[CampaignRecipient::PENDING] ?? 0);

        $campaign->update([
            'sent_count' => (int) ($counts[CampaignRecipient::SENT] ?? 0),
            'failed_count' => (int) ($counts[CampaignRecipient::FAILED] ?? 0),
            'status' => $pending > 0 ? Campaign::SENDING : Campaign::SENT,
            'finished_at' => $pending > 0 ? null : now(),
        ]);
    }
}
