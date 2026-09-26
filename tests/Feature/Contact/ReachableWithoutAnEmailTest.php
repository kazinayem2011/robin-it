<?php

namespace Tests\Feature\Contact;

use App\Mail\ContactReplyMail;
use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\SmsService;
use App\Support\BrandDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Writing in without an email address.
 *
 * The form demanded one from everybody, including the customers who registered
 * with a mobile number and have none. Their two ways out were both wrong:
 * invent an address the shop cannot reach them at, or type somebody else's,
 * which is how an answer ends up in a stranger's inbox.
 */
class ReachableWithoutAnEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);

        $this->staff = User::factory()->admin()->create(['name' => 'Nazmul']);
    }

    private function withTexts(): void
    {
        config([
            'services.sms.enabled' => true,
            'services.sms.token' => 'test-token',
            'services.sms.log_fallback' => false,
        ]);
    }

    /** @return list<string> */
    private function textsSent(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function send(array $fields, ?User $as = null)
    {
        $request = $as ? $this->actingAs($as) : $this;

        return $request->postJson('/api/contact', array_merge([
            'name' => 'Karim Uddin',
            'subject' => 'Where is my order?',
            'message' => 'It has been a week since I ordered the build.',
        ], $fields));
    }

    public function test_a_customer_registered_by_mobile_needs_no_address(): void
    {
        $byPhone = User::factory()->create(['phone' => '01341789939', 'email' => null]);

        $this->send([], $byPhone)->assertSuccessful();

        $message = ContactMessage::latest('id')->firstOrFail();
        $this->assertNull($message->email);
        $this->assertSame($byPhone->id, $message->user_id);
    }

    /** The answer waits in their messages, so nothing is emailed and nothing failed. */
    public function test_the_answer_reaches_them_without_an_address(): void
    {
        $byPhone = User::factory()->create(['phone' => '01341789939', 'email' => null]);
        $this->send([], $byPhone)->assertSuccessful();
        $message = ContactMessage::latest('id')->firstOrFail();

        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'It ships tomorrow.'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Replied. They will see it in their messages.');

        Mail::assertNotSent(ContactReplyMail::class);

        $reply = ContactReply::latest('id')->firstOrFail();
        $this->assertFalse((bool) $reply->emailed);

        $this->assertSame('contact.answered', $byPhone->notifications()->sole()->data['kind']);

        $this->actingAs($byPhone)
            ->get('/dashboard/messages')
            ->assertInertia(fn ($page) => $page->where('threads.0.replies.0.body', 'It ships tomorrow.'));
    }

    /** A guest leaves a number; an address is theirs to add or not. */
    public function test_a_guest_may_leave_a_number_instead_of_an_address(): void
    {
        $this->send(['phone' => '01341789939'])
            ->assertSuccessful()
            // No gateway here, so the shop promises the call it would make.
            ->assertJsonPath('message', 'Thanks Karim Uddin — we have your message and will call you on 01341789939.');

        $message = ContactMessage::latest('id')->firstOrFail();
        $this->assertNull($message->email);
        $this->assertSame('01341789939', $message->phone);

        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'Ringing you now.'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Saved. This enquiry left no email address — call 01341789939.');
    }

    /**
     * The answer itself, to somebody the shop can reach no other way.
     *
     * A guest who leaves a number has no thread to read and no inbox to mail,
     * so an answer saved in the inbox reached them nowhere at all: staff were
     * left ringing people to say "yes, in stock".
     */
    public function test_a_guest_who_left_a_number_is_texted_the_answer(): void
    {
        $this->withTexts();
        $this->send(['phone' => '01341789939'])
            ->assertSuccessful()
            // And says so, rather than promising a phone call.
            ->assertJsonPath('message', 'Thanks Karim Uddin — we have your message and will text you on 01341789939.');
        $message = ContactMessage::latest('id')->firstOrFail();

        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'Yes, three in Uttara.'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Replied by text to 01341789939.')
            ->assertJsonPath('data.texted', true);

        $texts = $this->textsSent();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('Yes, three in Uttara.', $texts[0]);
        // The gateway refuses a message with no Bengali in it.
        $this->assertMatchesRegularExpression('/\p{Bengali}/u', $texts[0]);
    }

    /** Too long to text is a note saying an answer is waiting, not four parts. */
    public function test_a_long_answer_becomes_a_note_with_the_hotline(): void
    {
        $this->withTexts();
        $this->send(['phone' => '01341789939'])->assertSuccessful();
        $message = ContactMessage::latest('id')->firstOrFail();

        $long = str_repeat('It is in stock and we can deliver tomorrow morning. ', 6);

        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => $long])
            ->assertSuccessful();

        $text = $this->textsSent()[0];
        $this->assertStringNotContainsString('deliver tomorrow morning', $text);
        $this->assertLessThanOrEqual(2, SmsService::parts($text));
        $this->assertStringContainsString(BrandDetails::all()['hotline'], $text);
    }

    /** A customer has the answer in their messages already; a text would repeat it. */
    public function test_a_signed_in_customer_is_not_texted_as_well(): void
    {
        $this->withTexts();
        $byPhone = User::factory()->create(['phone' => '01341789939', 'email' => null]);

        // With their number on the message, so nothing but the rule stops it.
        $this->send(['phone' => '01341789939'], $byPhone)->assertSuccessful();
        $message = ContactMessage::latest('id')->firstOrFail();
        $this->assertSame('01341789939', $message->phone);

        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'It ships tomorrow.'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Replied. They will see it in their messages.');

        $this->assertSame([], $this->textsSent());
    }

    public function test_with_the_switch_off_the_inbox_says_to_call(): void
    {
        $this->withTexts();
        SiteSetting::set('sms_on_contact_reply', '0', 'sms');

        $this->send(['phone' => '01341789939'])->assertSuccessful();
        $message = ContactMessage::latest('id')->firstOrFail();

        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'Yes, three in Uttara.'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Saved. This enquiry left no email address — call 01341789939.');

        $this->assertSame([], $this->textsSent());
    }

    public function test_a_guest_with_neither_is_asked_for_a_number(): void
    {
        $this->send([])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.phone.0', 'Leave us a mobile number, so we can reply.');

        $this->assertSame(0, ContactMessage::count());
    }

    /*
     * The number is what the form asks a guest for; an address alone left the
     * shop a reply that might never be read, and nobody it could ring.
     */
    public function test_a_guest_with_an_address_alone_is_asked_for_a_number_too(): void
    {
        $this->send(['email' => 'karim@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone', 'data.errors');

        $this->send(['email' => 'karim@example.com', 'phone' => '01341789939'])->assertSuccessful();

        $message = ContactMessage::latest('id')->first();
        $this->assertSame('karim@example.com', $message->email);
        $this->assertSame('01341789939', $message->phone);
    }

    public function test_a_signed_in_customer_needs_neither(): void
    {
        $byAddress = User::factory()->create(['phone' => null]);

        $this->send([], $byAddress)->assertSuccessful();
    }
}
