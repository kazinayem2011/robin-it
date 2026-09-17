<?php

namespace Tests\Feature\Contact;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The inbox says who it is actually talking to.
 *
 * The contact form is open to anyone and the address on a message is simply
 * what was typed, so an enquiry from a customer's address is not proof it came
 * from that customer. Staff read the address, take it for the account, and
 * answer with what is on it — at the word of somebody who never proved
 * anything. The screen showed nothing to tell those apart.
 */
class InboxSenderTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->staff = User::factory()->admin()->create(['name' => 'Nazmul']);
    }

    private function write(array $overrides = [], ?User $as = null): void
    {
        $request = $as ? $this->actingAs($as) : $this;

        $request->postJson('/api/contact', array_merge([
            'name' => 'Karim Uddin',
            'email' => 'karim@example.com',
            'subject' => 'A question',
            'message' => 'About the warranty on my build.',
        ], $overrides))->assertSuccessful();
    }

    private function inbox()
    {
        return $this->actingAs($this->staff)->get('/admin/messages');
    }

    public function test_a_signed_in_customer_is_named(): void
    {
        $customer = User::factory()->create(['name' => 'Karim Uddin', 'email' => 'karim@example.com']);

        $this->write(as: $customer);

        $this->inbox()->assertInertia(fn ($page) => $page
            ->where('messages.data.0.sender.signed_in', true)
            ->where('messages.data.0.sender.account_name', 'Karim Uddin')
            ->where('messages.data.0.sender.address_has_account', false));
    }

    /** The one to be careful with: it looks exactly like a customer. */
    public function test_a_guest_using_an_accounts_address_is_flagged(): void
    {
        User::factory()->create(['email' => 'karim@example.com']);

        $this->write();

        $this->inbox()->assertInertia(fn ($page) => $page
            ->where('messages.data.0.sender.signed_in', false)
            ->where('messages.data.0.sender.address_has_account', true));
    }

    public function test_a_plain_guest_is_neither(): void
    {
        $this->write(['email' => 'nobody@example.com']);

        $this->inbox()->assertInertia(fn ($page) => $page
            ->where('messages.data.0.sender.signed_in', false)
            ->where('messages.data.0.sender.address_has_account', false));
    }

    /**
     * A thread has two voices now, and the screen marks which is which — which
     * it cannot do if the column never reaches it.
     */
    public function test_the_thread_says_which_replies_came_from_the_customer(): void
    {
        $customer = User::factory()->create(['name' => 'Karim Uddin', 'email' => 'karim@example.com']);
        $this->write(as: $customer);
        $message = ContactMessage::latest('id')->firstOrFail();

        $this->actingAs($this->staff)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'Yes, three in Uttara.'])
            ->assertSuccessful();

        $this->actingAs($customer)
            ->post("/account/messages/{$message->id}/replies", ['body' => 'Please hold one.'])
            ->assertRedirect();

        $this->inbox()->assertInertia(fn ($page) => $page
            ->where('messages.data.0.replies.0.from_customer', false)
            ->where('messages.data.0.replies.1.from_customer', true));
    }
}
