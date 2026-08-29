<?php

namespace Tests\Feature\Marketing;

use App\Jobs\SendCampaignMessage;
use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\SmsService;
use App\Support\BrandDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Telling every customer about something at once.
 *
 * The shop had spent a year collecting a mailing list with nowhere to send it.
 *
 * Most of what is tested here is restraint rather than reach: who must not be
 * written to, what a text will cost before it is sent, and how a blast that
 * stops half way is picked up without sending everybody a second copy.
 */
class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private CampaignService $campaigns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campaigns = app(CampaignService::class);
    }

    private function campaign(array $overrides = []): Campaign
    {
        return Campaign::create(array_merge([
            'title' => 'Eid sale',
            'subject' => '15% off every build',
            'body' => 'Our Eid sale starts Thursday.',
            'channel' => Campaign::EMAIL,
            'audience' => 'all',
            'status' => Campaign::DRAFT,
        ], $overrides));
    }

    private function subscriber(string $email, string $status = Subscriber::SUBSCRIBED): Subscriber
    {
        return Subscriber::create([
            'email' => $email, 'name' => 'Listy',
            'status' => $status, 'token' => Subscriber::newToken(),
            'subscribed_at' => now(),
            'unsubscribed_at' => $status === Subscriber::UNSUBSCRIBED ? now() : null,
        ]);
    }

    private function customer(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
            'accepts_marketing' => true,
            'phone' => '017'.rand(10000000, 99999999),
        ], $overrides));
    }

    // --- who must not be written to ----------------------------------------

    /**
     * The bug worth not copying: a list that includes the people who left it
     * is not a list, it is a liability.
     */
    public function test_somebody_who_left_the_list_is_not_written_to(): void
    {
        $this->subscriber('still@example.com');
        $this->subscriber('gone@example.com', Subscriber::UNSUBSCRIBED);

        $emails = $this->campaigns
            ->audienceFor($this->campaign(['audience' => 'subscribers']))
            ->pluck('email');

        $this->assertContains('still@example.com', $emails);
        $this->assertNotContains('gone@example.com', $emails);
    }

    /**
     * Leaving once has to mean leaving everywhere. Being mailed anyway because
     * you also have an account reads as the link not working, and the next
     * thing pressed is the spam button.
     */
    public function test_unsubscribing_also_stops_the_customer_list(): void
    {
        $customer = $this->customer(['email' => 'both@example.com']);
        $this->subscriber('both@example.com', Subscriber::UNSUBSCRIBED);

        $emails = $this->campaigns
            ->audienceFor($this->campaign(['audience' => 'all']))
            ->pluck('email');

        $this->assertNotContains('both@example.com', $emails);
        $this->assertTrue($customer->fresh()->accepts_marketing, 'flag untouched by the audience query');
    }

    /** And clicking the link in an email switches the account flag off too. */
    public function test_the_unsubscribe_link_switches_the_account_off(): void
    {
        $customer = $this->customer(['email' => 'both@example.com']);
        $subscriber = $this->subscriber('both@example.com');

        $this->get("/unsubscribe/{$subscriber->token}")->assertOk();

        $this->assertFalse($customer->fresh()->accepts_marketing);
        $this->assertSame(Subscriber::UNSUBSCRIBED, $subscriber->fresh()->status);
    }

    public function test_a_customer_who_opted_out_is_not_written_to(): void
    {
        $this->customer(['email' => 'yes@example.com']);
        $this->customer(['email' => 'no@example.com', 'accepts_marketing' => false]);

        $emails = $this->campaigns
            ->audienceFor($this->campaign(['audience' => 'customers']))
            ->pluck('email');

        $this->assertContains('yes@example.com', $emails);
        $this->assertNotContains('no@example.com', $emails);
    }

    public function test_staff_are_not_customers(): void
    {
        User::factory()->create(['role' => 'admin', 'email' => 'boss@example.com']);
        $this->customer(['email' => 'buyer@example.com']);

        $emails = $this->campaigns
            ->audienceFor($this->campaign(['audience' => 'customers']))
            ->pluck('email');

        $this->assertNotContains('boss@example.com', $emails);
        $this->assertContains('buyer@example.com', $emails);
    }

    /** Somebody on the list who is also a customer gets one copy, not two. */
    public function test_nobody_is_written_to_twice(): void
    {
        $this->customer(['email' => 'both@example.com']);
        $this->subscriber('both@example.com');

        $campaign = $this->campaign(['audience' => 'all']);

        Queue::fake();
        $this->campaigns->send($campaign);

        $this->assertSame(1, CampaignRecipient::where('channel', 'email')->count());
    }

    // --- what it will cost -------------------------------------------------

    /**
     * The bill, before it is a bill.
     *
     * One em dash pushes a whole message into unicode, where 70 characters fit
     * instead of 160 — on five thousand numbers that is a doubled invoice for
     * a character nobody saw.
     */
    public function test_the_estimate_prices_the_texts_in_parts_not_messages(): void
    {
        foreach (range(1, 3) as $i) {
            $this->customer(['email' => "c{$i}@example.com", 'phone' => "0171000000{$i}"]);
        }

        $plain = $this->campaigns->estimate($this->campaign([
            'channel' => Campaign::SMS, 'audience' => 'customers',
            'body' => 'Eid sale starts Thursday. Come in.',
        ]));

        $this->assertSame(3, $plain['texts']);
        $this->assertSame(3, $plain['sms_parts']);
        $this->assertFalse($plain['unicode']);

        // The same sentence with one em dash in it.
        $fancy = $this->campaigns->estimate($this->campaign([
            'channel' => Campaign::SMS, 'audience' => 'customers',
            'body' => str_repeat('Eid sale — come in. ', 6),
        ]));

        $this->assertTrue($fancy['unicode']);
        $this->assertGreaterThan($plain['sms_parts'], $fancy['sms_parts']);
    }

    public function test_the_estimate_sends_nothing(): void
    {
        Mail::fake();
        Queue::fake();
        $this->customer();

        $this->campaigns->estimate($this->campaign());

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, CampaignRecipient::count());
    }

    /** A text campaign only reaches people whose number the shop holds. */
    public function test_the_mailing_list_has_no_phone_numbers_to_text(): void
    {
        $this->subscriber('list@example.com');

        $estimate = $this->campaigns->estimate($this->campaign([
            'channel' => Campaign::SMS, 'audience' => 'subscribers',
        ]));

        $this->assertSame(0, $estimate['texts']);
    }

    // --- sending it ---------------------------------------------------------

    /**
     * Written down before anything goes out, which is what makes a blast
     * resumable — and queued, because five thousand sends inside the request
     * that pressed the button times out in the middle with no record of how
     * far it got.
     */
    public function test_sending_writes_every_recipient_down_first_and_queues(): void
    {
        Queue::fake();

        $this->customer(['email' => 'a@example.com', 'phone' => '01710000001']);
        $this->customer(['email' => 'b@example.com', 'phone' => '01710000002']);

        $campaign = $this->campaigns->send($this->campaign([
            'channel' => Campaign::BOTH, 'audience' => 'customers',
        ]));

        // Two people, two channels.
        $this->assertSame(4, CampaignRecipient::count());
        $this->assertSame(4, $campaign->recipient_count);
        $this->assertSame(Campaign::SENDING, $campaign->status);

        Queue::assertPushed(SendCampaignMessage::class);
    }

    public function test_a_campaign_cannot_be_sent_twice(): void
    {
        Queue::fake();
        $this->customer();

        $campaign = $this->campaigns->send($this->campaign());

        $this->expectExceptionMessage('already going out');
        $this->campaigns->send($campaign);
    }

    public function test_a_campaign_nobody_can_receive_is_refused(): void
    {
        $this->expectExceptionMessage('Nobody in that audience can be reached');
        $this->campaigns->send($this->campaign(['audience' => 'customers']));
    }

    // --- the job ------------------------------------------------------------

    public function test_the_job_sends_and_marks_each_recipient(): void
    {
        Mail::fake();

        $this->customer(['email' => 'a@example.com']);
        $campaign = $this->campaigns->send($this->campaign(['audience' => 'customers']));

        $ids = $campaign->recipients()->pluck('id')->all();
        (new SendCampaignMessage($campaign->id, $ids))->handle(
            app(CampaignService::class),
            app(SmsService::class)
        );

        Mail::assertSent(CampaignMail::class, 1);

        $campaign->refresh();
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(Campaign::SENT, $campaign->status);
        $this->assertNotNull($campaign->finished_at);
    }

    /**
     * The reason recipients are rows rather than a counter: a batch re-run
     * after a failure must not reach the people it already reached.
     */
    public function test_running_a_batch_again_does_not_send_twice(): void
    {
        Mail::fake();

        $this->customer(['email' => 'a@example.com']);
        $campaign = $this->campaigns->send($this->campaign(['audience' => 'customers']));
        $ids = $campaign->recipients()->pluck('id')->all();

        $job = new SendCampaignMessage($campaign->id, $ids);
        $job->handle(app(CampaignService::class), app(SmsService::class));
        $job->handle(app(CampaignService::class), app(SmsService::class));

        Mail::assertSent(CampaignMail::class, 1);
        $this->assertSame(1, $campaign->fresh()->sent_count);
    }

    /** One bad address must not stop the other four thousand. */
    public function test_one_failure_is_recorded_and_the_rest_continue(): void
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Mailbox full'));

        $this->customer(['email' => 'a@example.com']);
        $campaign = $this->campaigns->send($this->campaign(['audience' => 'customers']));

        (new SendCampaignMessage($campaign->id, $campaign->recipients()->pluck('id')->all()))
            ->handle(app(CampaignService::class), app(SmsService::class));

        $recipient = CampaignRecipient::first();

        $this->assertSame(CampaignRecipient::FAILED, $recipient->status);
        $this->assertStringContainsString('Mailbox full', $recipient->error);
        $this->assertSame(1, $campaign->fresh()->failed_count);
    }

    /**
     * Every marketing email carries a way out. Without one the way to stop a
     * shop's email is the spam button, which costs the shop its deliverability
     * for everybody still on the list.
     */
    public function test_a_customer_who_was_never_on_the_list_still_gets_an_unsubscribe_link(): void
    {
        Mail::fake();

        $this->customer(['email' => 'never@example.com']);
        $campaign = $this->campaigns->send($this->campaign(['audience' => 'customers']));

        (new SendCampaignMessage($campaign->id, $campaign->recipients()->pluck('id')->all()))
            ->handle(app(CampaignService::class), app(SmsService::class));

        $subscriber = Subscriber::firstWhere('email', 'never@example.com');

        $this->assertNotNull($subscriber, 'A token has to exist for the link to work.');
        Mail::assertSent(CampaignMail::class, fn ($mail) => str_contains($mail->unsubscribeUrl, $subscriber->token));
    }

    /** The text carries the shop's name, or it reads as spam from a short code. */
    public function test_a_text_says_who_it_is_from(): void
    {
        $body = $this->campaigns->smsBody($this->campaign(['body' => 'Eid sale starts Thursday.']));

        $this->assertStringStartsWith(BrandDetails::name().':', $body);
        $this->assertStringContainsString('Eid sale starts Thursday.', $body);
    }

    public function test_a_name_is_put_where_the_writer_asked_for_it(): void
    {
        $this->assertSame(
            'Salam Rahim, our sale starts Thursday.',
            $this->campaigns->personalise('Salam {name}, our sale starts Thursday.', 'Rahim')
        );

        // No name is "there", not "Valued Customer" — which announces itself
        // as a circular, the one thing a circular should not do.
        $this->assertSame(
            'Salam there, our sale starts Thursday.',
            $this->campaigns->personalise('Salam {name}, our sale starts Thursday.', null)
        );
    }

    // --- through the endpoints ----------------------------------------------

    public function test_an_owner_can_write_preview_and_send(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $this->customer(['email' => 'a@example.com', 'phone' => '01710000009']);

        $created = $this->actingAs($owner)->postJson('/api/admin/campaigns', [
            'title' => 'Eid sale', 'subject' => '15% off',
            'body' => 'Our Eid sale starts Thursday.',
            'channel' => 'both', 'audience' => 'customers',
        ])->assertOk()->json('data');

        $this->actingAs($owner)->postJson('/api/admin/campaigns/preview', [
            'title' => 'Eid sale', 'subject' => '15% off',
            'body' => 'Our Eid sale starts Thursday.',
            'channel' => 'both', 'audience' => 'customers',
        ])->assertOk()
            ->assertJsonPath('data.emails', 1)
            ->assertJsonPath('data.texts', 1);

        $this->actingAs($owner)->postJson("/api/admin/campaigns/{$created['id']}/send")->assertOk();

        $this->assertSame(2, CampaignRecipient::count());
    }

    public function test_a_sent_campaign_cannot_be_rewritten(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $this->customer();

        $campaign = $this->campaigns->send($this->campaign());

        $this->actingAs($owner)
            ->putJson("/api/admin/campaigns/{$campaign->id}", [
                'title' => 'Changed', 'subject' => 'x', 'body' => 'Different words.',
                'channel' => 'email', 'audience' => 'all',
            ])
            ->assertStatus(422);
    }

    public function test_an_email_campaign_needs_a_subject(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/campaigns', [
                'title' => 'Eid sale', 'body' => 'Words.',
                'channel' => 'email', 'audience' => 'all',
            ])
            ->assertStatus(422);
    }

    public function test_broadcasting_needs_the_marketing_ability(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'storekeeper']))
            ->postJson('/api/admin/campaigns', [
                'title' => 'Eid sale', 'subject' => 'x', 'body' => 'Words.',
                'channel' => 'email', 'audience' => 'all',
            ])
            ->assertStatus(403);
    }

    public function test_the_page_lists_them(): void
    {
        $this->campaign();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/campaigns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Campaigns')->has('campaigns.data', 1));
    }
}
