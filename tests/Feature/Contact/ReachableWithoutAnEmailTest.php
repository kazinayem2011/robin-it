<?php

namespace Tests\Feature\Contact;

use App\Mail\ContactReplyMail;
use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->staff = User::factory()->admin()->create(['name' => 'Nazmul']);
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

    /** A guest is asked for one or the other, so there is always a way to answer. */
    public function test_a_guest_may_leave_a_number_instead_of_an_address(): void
    {
        $this->send(['phone' => '01341789939'])->assertSuccessful();

        $message = ContactMessage::latest('id')->firstOrFail();
        $this->assertNull($message->email);
        $this->assertSame('01341789939', $message->phone);

        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'Ringing you now.'])
            ->assertSuccessful()
            ->assertJsonPath('message', 'Saved. This enquiry left no email address — call 01341789939.');
    }

    public function test_a_guest_with_neither_is_asked_for_one(): void
    {
        $this->send([])
            ->assertStatus(422)
            ->assertJsonPath(
                'data.errors.email.0',
                'Leave us an email address or a mobile number, so we can reply.'
            );

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_a_guest_with_an_address_alone_is_unchanged(): void
    {
        $this->send(['email' => 'karim@example.com'])->assertSuccessful();

        $this->assertSame('karim@example.com', ContactMessage::latest('id')->first()->email);
    }
}
