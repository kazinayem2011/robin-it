<?php

namespace Tests\Feature\Contact;

use App\Mail\ContactReplyMail;
use App\Models\ContactMessage;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "About us", "Contact us" and the newsletter box.
 *
 * The footer linked to /about and /contact from the day the site was built and
 * both were 404s, and its newsletter box was a form whose only handler was
 * onSubmit={(e) => e.preventDefault()} — an address typed into it went nowhere.
 */
class ContactAndSubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahim Chowdhury',
            'email' => 'Rahim@Example.com',
            'phone' => '01711223344',
            'subject' => 'Warranty on an RTX 4090',
            'message' => 'Is the three year warranty handled locally?',
        ], $overrides);
    }

    private function staff(string $role = Roles::SUPPORT): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    // --- The pages themselves ------------------------------------------

    public function test_the_pages_the_footer_links_to_exist(): void
    {
        $this->get('/about')->assertStatus(200);
        $this->get('/contact')->assertStatus(200);
    }

    public function test_the_contact_form_is_filled_in_for_a_signed_in_customer(): void
    {
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'name' => 'Rahim Chowdhury',
        ]);

        $props = $this->actingAs($customer)->get('/contact')->viewData('page')['props'];

        $this->assertSame('Rahim Chowdhury', $props['contact']['name']);
        $this->assertSame($customer->email, $props['contact']['email']);
    }

    public function test_a_guest_is_asked_for_their_details(): void
    {
        $props = $this->get('/contact')->viewData('page')['props'];

        $this->assertNull($props['contact']);
    }

    // --- Writing in ----------------------------------------------------

    public function test_a_message_is_kept(): void
    {
        $this->postJson('/api/contact', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('error', false);

        $message = ContactMessage::sole();

        $this->assertSame('Rahim Chowdhury', $message->name);
        // Lower-cased, so the same person is one person.
        $this->assertSame('rahim@example.com', $message->email);
        $this->assertSame(ContactMessage::STATUS_NEW, $message->status);
        $this->assertNotNull($message->ip_address);
    }

    public function test_the_phone_is_optional_but_must_be_real_when_given(): void
    {
        $this->postJson('/api/contact', $this->payload(['phone' => null]))
            ->assertStatus(201);

        $this->postJson('/api/contact', $this->payload(['phone' => '12345']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    /** The app writes numbers as "+880 1711-223344"; it must accept its own. */
    public function test_a_phone_written_the_way_the_app_prints_it_is_accepted(): void
    {
        $this->postJson('/api/contact', $this->payload(['phone' => '+880 1711-223344']))
            ->assertStatus(201);

        $this->assertSame('01711223344', ContactMessage::sole()->phone);
    }

    public function test_a_message_needs_enough_to_go_on(): void
    {
        $this->postJson('/api/contact', $this->payload(['message' => 'hi']))
            ->assertStatus(422);

        $this->assertSame(0, ContactMessage::count());
    }

    // --- The mailing list ----------------------------------------------

    public function test_subscribing_adds_an_address(): void
    {
        $this->postJson('/api/subscribe', ['email' => 'Reader@Example.com'])
            ->assertSuccessful();

        $subscriber = Subscriber::sole();

        $this->assertSame('reader@example.com', $subscriber->email);
        $this->assertTrue($subscriber->isSubscribed());
        $this->assertNotEmpty($subscriber->token);
    }

    /**
     * Signing up twice is not an error to show a visitor.
     *
     * They cannot see the list, so "you are already subscribed" would tell
     * anyone with a guess who is on it.
     */
    public function test_subscribing_twice_says_the_same_thing_and_adds_one_row(): void
    {
        $first = $this->postJson('/api/subscribe', ['email' => 'reader@example.com']);
        $second = $this->postJson('/api/subscribe', ['email' => 'reader@example.com']);

        $first->assertSuccessful();
        $second->assertSuccessful();
        $this->assertSame($first->json('message'), $second->json('message'));
        $this->assertSame(1, Subscriber::count());
    }

    public function test_the_token_never_leaves_the_server(): void
    {
        $this->postJson('/api/subscribe', ['email' => 'reader@example.com'])
            ->assertSuccessful()
            ->assertJsonMissing(['token' => Subscriber::sole()->token]);

        $this->assertArrayNotHasKey('token', Subscriber::sole()->toArray());
    }

    public function test_a_link_takes_someone_off_the_list(): void
    {
        $subscriber = app(SubscriptionService::class)->subscribe('reader@example.com');

        $this->get('/unsubscribe/'.$subscriber->token)
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('done', true));

        $this->assertFalse($subscriber->fresh()->isSubscribed());
        // The row stays: it is what stops the next import adding them back.
        $this->assertSame(1, Subscriber::count());
    }

    public function test_a_stale_or_invented_link_says_nothing(): void
    {
        $this->get('/unsubscribe/'.str_repeat('z', 40))
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('done', false)->where('email', null));
    }

    public function test_coming_back_gets_a_fresh_token(): void
    {
        $service = app(SubscriptionService::class);
        $subscriber = $service->subscribe('reader@example.com');
        $old = $subscriber->token;

        $service->unsubscribeByToken($old);
        $back = $service->subscribe('reader@example.com');

        $this->assertTrue($back->isSubscribed());
        // Or a link in an old email would take them off again.
        $this->assertNotSame($old, $back->token);
    }

    // --- Answering -----------------------------------------------------

    public function test_replying_emails_the_customer_and_takes_it_off_the_new_pile(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', $this->payload())->assertStatus(201);
        $message = ContactMessage::sole();

        $this->actingAs($this->staff())
            ->postJson("/api/admin/messages/{$message->id}/reply", [
                'body' => 'Handled locally — bring it to any showroom.',
            ])->assertSuccessful();

        Mail::assertSent(ContactReplyMail::class, fn ($mail) => $mail->hasTo('rahim@example.com'));

        $message->refresh();
        $this->assertSame(ContactMessage::STATUS_OPEN, $message->status);
        $this->assertCount(1, $message->replies);
        $this->assertTrue($message->replies->first()->emailed);
    }

    public function test_a_reply_can_close_it_in_one_go(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', $this->payload())->assertStatus(201);
        $message = ContactMessage::sole();

        $this->actingAs($this->staff())
            ->postJson("/api/admin/messages/{$message->id}/reply", [
                'body' => 'Yes — three years.',
                'close' => true,
            ])->assertSuccessful();

        $this->assertTrue($message->fresh()->is_closed);
        $this->assertNotNull($message->fresh()->closed_at);
    }

    /**
     * A mail server that is down must not lose what somebody wrote.
     *
     * The answer is recorded either way, marked as not emailed, and the person
     * who sent it is told rather than shown a 500.
     */
    public function test_a_reply_is_kept_even_when_the_email_will_not_go(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP down'));

        $this->postJson('/api/contact', $this->payload())->assertStatus(201);
        $message = ContactMessage::sole();

        $response = $this->actingAs($this->staff())
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'Sorry for the delay.'])
            ->assertSuccessful();

        $this->assertFalse($response->json('data.emailed'));
        $this->assertStringContainsString('could not be sent', $response->json('message'));
        $this->assertCount(1, $message->fresh()->replies);
        $this->assertFalse($message->fresh()->replies->first()->emailed);
    }

    public function test_closing_and_reopening(): void
    {
        $this->postJson('/api/contact', $this->payload())->assertStatus(201);
        $message = ContactMessage::sole();
        $staff = $this->staff();

        $this->actingAs($staff)
            ->patchJson("/api/admin/messages/{$message->id}/status", ['status' => 'closed'])
            ->assertSuccessful();

        $this->assertTrue($message->fresh()->is_closed);
        $this->assertSame($staff->id, $message->fresh()->closed_by);

        $this->actingAs($staff)
            ->patchJson("/api/admin/messages/{$message->id}/status", ['status' => 'open'])
            ->assertSuccessful();

        $message->refresh();
        // Back to "in progress", not "new": it has been seen.
        $this->assertSame(ContactMessage::STATUS_OPEN, $message->status);
        $this->assertNull($message->closed_at);
        $this->assertNull($message->closed_by);
    }

    // --- Who may do it -------------------------------------------------

    public function test_the_inbox_belongs_to_support(): void
    {
        $this->postJson('/api/contact', $this->payload())->assertStatus(201);
        $message = ContactMessage::sole();

        $this->actingAs($this->staff(Roles::SUPPORT))->get('/admin/messages')->assertStatus(200);

        // A storekeeper does not answer the post.
        $keeper = $this->staff(Roles::STOREKEEPER);
        $this->actingAs($keeper)->get('/admin/messages')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($keeper)
            ->postJson("/api/admin/messages/{$message->id}/reply", ['body' => 'Hello'])
            ->assertStatus(403);
    }

    public function test_the_mailing_list_belongs_to_marketing(): void
    {
        $this->actingAs($this->staff(Roles::MANAGER))->get('/admin/subscribers')->assertStatus(200);

        $this->actingAs($this->staff(Roles::STOREKEEPER))
            ->get('/admin/subscribers')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_customer_cannot_reach_the_inbox(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->get('/admin/messages')
            ->assertRedirect();
    }

    /** Removing someone by hand keeps the record that they asked. */
    public function test_removing_a_subscriber_marks_them_rather_than_deleting_them(): void
    {
        $subscriber = app(SubscriptionService::class)->subscribe('reader@example.com');

        $this->actingAs($this->staff(Roles::MANAGER))
            ->deleteJson("/api/admin/subscribers/{$subscriber->id}")
            ->assertSuccessful();

        $this->assertSame(1, Subscriber::count());
        $this->assertFalse($subscriber->fresh()->isSubscribed());
    }
}
