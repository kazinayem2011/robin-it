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
 * A customer's own side of the contact inbox.
 *
 * An enquiry was the shop's record alone: answered by email, with nothing on
 * the site saying what was asked, whether it had been answered, or where to
 * write back — an email reply lands in a mailbox, not in the inbox screen, so
 * the shop could not see it beside what it had said.
 */
class CustomerThreadTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->customer = User::factory()->create([
            'name' => 'Karim Uddin',
            'email' => 'karim@example.com',
        ]);
        $this->staff = User::factory()->admin()->create(['name' => 'Nazmul']);
    }

    private function write(?User $as = null): ContactMessage
    {
        $request = $as ? $this->actingAs($as) : $this;

        $request->postJson('/api/contact', [
            'name' => 'Karim Uddin',
            'email' => 'karim@example.com',
            'subject' => 'Is the RTX 4060 in stock?',
            'message' => 'Asking about the Gigabyte one, in Uttara.',
        ])->assertSuccessful();

        return ContactMessage::latest('id')->firstOrFail();
    }

    private function staffReplies(ContactMessage $message, string $body = 'Yes, three in Uttara.'): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => $body])
            ->assertSuccessful();
    }

    public function test_an_enquiry_sent_while_signed_in_belongs_to_that_account(): void
    {
        $message = $this->write($this->customer);

        $this->assertSame($this->customer->id, $message->user_id);

        $this->actingAs($this->customer)
            ->get('/dashboard/messages')
            ->assertInertia(fn ($page) => $page
                ->where('threads.0.subject', 'Is the RTX 4060 in stock?')
                ->where('navCounts.messages', 1));
    }

    /** The form stays open to anyone, and a guest's enquiry has no thread. */
    public function test_a_guests_enquiry_belongs_to_nobody(): void
    {
        $message = $this->write();

        $this->assertNull($message->user_id);

        $this->actingAs($this->customer)
            ->get('/dashboard/messages')
            ->assertInertia(fn ($page) => $page->where('threads', []));
    }

    public function test_the_shops_answer_reaches_the_thread_the_bell_and_the_inbox(): void
    {
        $message = $this->write($this->customer);

        $this->staffReplies($message);

        $this->actingAs($this->customer)
            ->get('/dashboard/messages')
            ->assertInertia(fn ($page) => $page
                ->where('threads.0.replies.0.body', 'Yes, three in Uttara.')
                ->where('threads.0.replies.0.author_name', 'Nazmul')
                ->where('threads.0.replies.0.from_customer', false));

        $bell = $this->customer->notifications()->sole();
        $this->assertSame('contact.answered', $bell->data['kind']);
        $this->assertStringContainsString('/dashboard/messages', $bell->data['url']);

        Mail::assertSent(ContactReplyMail::class, fn ($mail) => $mail->hasTo('karim@example.com'));
    }

    public function test_the_customer_can_write_back_and_the_shop_is_told(): void
    {
        $message = $this->write($this->customer);
        $this->staffReplies($message);
        $this->staff->notifications()->delete();

        $this->actingAs($this->customer)
            ->post("/account/messages/{$message->id}/replies", ['body' => 'Please hold one for me.'])
            ->assertRedirect();

        $reply = ContactReply::latest('id')->firstOrFail();
        $this->assertTrue($reply->from_customer);
        $this->assertSame($this->customer->id, $reply->user_id);
        $this->assertSame('Karim Uddin', $reply->author_name);

        $bell = $this->staff->notifications()->sole();
        $this->assertSame('contact.replied', $bell->data['kind']);

        // Nothing is emailed to the shop: it reads the thread.
        Mail::assertNotSent(ContactReplyMail::class, fn ($mail) => str_contains($mail->render(), 'Please hold one for me.'));
    }

    /** Writing again is the plainest statement that it was not finished. */
    public function test_writing_back_reopens_a_closed_thread(): void
    {
        $message = $this->write($this->customer);

        $this->actingAs($this->staff)
            ->patchJson("/api/admin/messages/{$message->id}/status", ['status' => 'closed'])
            ->assertSuccessful();
        $this->assertSame('closed', $message->fresh()->status);

        $this->actingAs($this->customer)
            ->post("/account/messages/{$message->id}/replies", ['body' => 'One more thing.'])
            ->assertRedirect();

        $message->refresh();
        $this->assertSame('open', $message->status);
        $this->assertNull($message->closed_at);
    }

    public function test_nobody_else_may_read_or_answer_a_thread(): void
    {
        $message = $this->write($this->customer);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post("/account/messages/{$message->id}/replies", ['body' => 'Let me in.'])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get('/dashboard/messages')
            ->assertInertia(fn ($page) => $page->where('threads', []));

        $this->assertSame(0, ContactReply::where('contact_message_id', $message->id)->count());
    }

    /** A guest's thread has no owner, so it cannot be claimed by signing in. */
    public function test_a_guests_thread_cannot_be_answered_by_a_customer(): void
    {
        $message = $this->write();

        $this->actingAs($this->customer)
            ->post("/account/messages/{$message->id}/replies", ['body' => 'Mine, surely.'])
            ->assertForbidden();
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $message = $this->write($this->customer);

        $this->actingAs($this->customer)
            ->post("/account/messages/{$message->id}/replies", ['body' => ' '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, ContactReply::count());
    }
}
