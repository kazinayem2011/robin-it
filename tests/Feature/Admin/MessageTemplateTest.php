<?php

namespace Tests\Feature\Admin;

use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing what the shop says, and being stopped from breaking it.
 *
 * The wording lived in seven Blade files and nine strings in code, so changing
 * a sentence meant a developer and a deploy — which in practice meant it never
 * changed. It is rows now, edited in a browser, which introduces a fault the
 * old arrangement could not have: a placeholder damaged in a rich text editor
 * is just text, saves without complaint, and is discovered by a customer.
 */
class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user);

        return $user;
    }

    private function emailTemplate(array $attributes = []): EmailTemplate
    {
        return EmailTemplate::create($attributes + [
            'key' => 'welcome',
            'name' => 'Welcome',
            'group' => 'Account',
            'subject' => 'Welcome to {shop_name}',
            'body' => '<p>Hi {customer_name},</p><p>Your account is ready.</p>',
            'variables' => ['shop_name', 'customer_name'],
        ]);
    }

    public function test_an_admin_can_reword_a_template(): void
    {
        $this->admin();
        $template = $this->emailTemplate();

        $this->patchJson("/api/admin/templates/email/{$template->id}", [
            'subject' => 'Welcome aboard, {shop_name}',
            'body' => '<p>Hello {customer_name}, glad to have you.</p>',
        ])->assertOk();

        $this->assertSame(
            '<p>Hello {customer_name}, glad to have you.</p>',
            $template->fresh()->body,
        );
    }

    /**
     * The fault the editor introduces. Click into the middle of
     * `{customer_name}`, type, and it silently becomes something that will
     * never be filled in — the template saves, and a customer receives
     * "Hi {customer_nam e},".
     */
    public function test_a_save_that_broke_a_placeholder_is_refused(): void
    {
        $this->admin();
        $template = $this->emailTemplate();

        $response = $this->patchJson("/api/admin/templates/email/{$template->id}", [
            'subject' => 'Welcome to {shop_name}',
            'body' => '<p>Hi {customer_nam e},</p>',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.missing', ['customer_name']);
        $this->assertStringContainsString('customer_name', $response->json('message'));

        /* And nothing was written. */
        $this->assertStringContainsString('{customer_name}', $template->fresh()->body);
    }

    /**
     * An editor will split a placeholder across elements. The raw markup still
     * contains both halves, so searching it would call the placeholder intact
     * — it is broken to a reader and to the substitution.
     */
    public function test_a_placeholder_split_across_tags_is_refused(): void
    {
        $this->admin();
        $template = $this->emailTemplate();

        $this->patchJson("/api/admin/templates/email/{$template->id}", [
            'subject' => 'Welcome to {shop_name}',
            'body' => '<p>Hi {customer_<strong></strong>name},</p>',
        ])->assertStatus(422);
    }

    /**
     * The same fault by another route. An editor that writes the braces as
     * entities stores something that reads correctly in the preview pane and
     * is never substituted, because `fill()` searches for a literal `{`.
     */
    public function test_a_placeholder_written_as_entities_is_refused(): void
    {
        $this->admin();
        $template = $this->emailTemplate();

        $this->patchJson("/api/admin/templates/email/{$template->id}", [
            'subject' => 'Welcome to {shop_name}',
            'body' => '<p>Hi &#123;customer_name&#125;,</p>',
        ])->assertStatus(422);
    }

    /** Wording the shop does not want is its own business. */
    public function test_dropping_wording_around_a_placeholder_is_allowed(): void
    {
        $this->admin();
        $template = $this->emailTemplate();

        $this->patchJson("/api/admin/templates/email/{$template->id}", [
            'subject' => 'Welcome to {shop_name}',
            'body' => '<p>{customer_name}</p>',
        ])->assertOk();
    }

    public function test_the_preview_fills_a_template_in(): void
    {
        $this->admin();
        $template = $this->emailTemplate();

        $response = $this->getJson("/api/admin/templates/email/{$template->id}/preview");

        $response->assertOk();
        $this->assertStringNotContainsString('{customer_name}', $response->json('data.html'));
        /* Inside the real layout, not the bare words. */
        $this->assertStringContainsString('<table', $response->json('data.html'));
    }

    /**
     * Counted on the filled message, not the template. A written
     * `{order_number}` is fourteen characters and an order number is eight, so
     * counting the template overstates the cost of every message.
     */
    public function test_an_sms_preview_counts_what_will_actually_be_sent(): void
    {
        $this->admin();

        $template = SmsTemplate::create([
            'key' => 'order_placed',
            'name' => 'Order received',
            'group' => 'Orders',
            'body' => '{shop_name}: order {order_number}',
            'variables' => ['shop_name', 'order_number'],
        ]);

        $response = $this->getJson("/api/admin/templates/sms/{$template->id}/preview");

        $response->assertOk();
        $this->assertStringNotContainsString('{order_number}', $response->json('data.body'));
        $this->assertSame(
            mb_strlen($response->json('data.body')),
            $response->json('data.characters'),
        );
        $this->assertGreaterThan(0, $response->json('data.parts'));
    }

    public function test_a_template_cannot_be_emptied(): void
    {
        $this->admin();
        $template = $this->emailTemplate();

        $this->patchJson("/api/admin/templates/email/{$template->id}", [
            'body' => '',
        ])->assertStatus(422);
    }

    public function test_the_screen_is_closed_to_someone_without_the_ability(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));

        /* Redirected away rather than shown a 403, like every admin screen. */
        $this->get('/admin/templates')->assertRedirect();
    }

    /** The substitution itself, without the HTTP layer around it. */
    public function test_an_unsupplied_placeholder_is_left_as_written(): void
    {
        $filled = MessageTemplate::fill(
            'Hi {customer_name}, order {order_number}',
            ['customer_name' => 'Rahim'],
        );

        $this->assertSame('Hi Rahim, order {order_number}', $filled);
    }
}
