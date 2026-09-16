<?php

namespace Tests\Feature\Mail;

use App\Mail\OrderConfirmationMail;
use App\Mail\WelcomeCustomerMail;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * That rewording an email template actually changes what a customer receives.
 *
 * It did not. The rows were edited, previewed and test-sent on the Message
 * Templates screen and read by nothing else — every real email came from a
 * Blade view — so a shop could rewrite its order confirmation, watch the
 * preview show the new words, and go on sending the old ones. The preview was
 * the dangerous half: it showed a change that had not happened.
 */
class StoredWordingTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $key, string $subject, string $body): EmailTemplate
    {
        return EmailTemplate::create([
            'key' => $key,
            'name' => $key,
            'group' => 'Orders',
            'subject' => $subject,
            'body' => $body,
            'variables' => [],
        ]);
    }

    private function order(): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-'.Str::random(6),
            'session_id' => Str::random(40),
            'status' => 'pending',
            'subtotal' => 84500, 'shipping_fee' => 0, 'discount' => 0, 'total' => 84500,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim Uddin', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);

        $order->items()->create([
            'product_name' => 'ASUS TUF Gaming A15',
            'price' => 84500, 'quantity' => 1, 'total' => 84500,
        ]);

        return $order->fresh()->load('items');
    }

    private function product(): Product
    {
        $category = Category::create([
            'name' => 'Laptop',
            'slug' => 'laptop-'.Str::random(6),
            'is_active' => true,
        ]);

        return Product::create([
            'name' => 'ASUS TUF Gaming A15',
            'slug' => 'asus-tuf-'.Str::random(6),
            'category_id' => $category->id,
            'price' => 84500,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);
    }

    /** @return array{0: ContactMessage, 1: ContactReply} */
    private function enquiry(): array
    {
        $message = ContactMessage::create([
            'name' => 'Rahim Uddin',
            'email' => 'rahim@example.com',
            'subject' => 'Is the RTX 4060 model in stock?',
            'message' => 'Asking about stock.',
        ]);

        $reply = ContactReply::create([
            'contact_message_id' => $message->id,
            'author_name' => 'Nazmul',
            'body' => 'Yes, three in Uttara.',
        ]);

        return [$message, $reply];
    }

    /** The rendered HTML of a mailable, as it would be sent. */
    private function render($mailable): string
    {
        return $mailable->render();
    }

    public function test_rewording_the_welcome_email_changes_what_is_sent(): void
    {
        $this->template(
            'welcome',
            'Welcome aboard, {customer_name}',
            '<p>Hello {customer_name}, {shop_name} is glad to have you.</p>',
        );

        $user = User::factory()->create(['name' => 'Rahim Uddin']);
        $mail = new WelcomeCustomerMail($user);

        $this->assertStringContainsString(
            'Hello Rahim Uddin,',
            $this->render($mail),
        );
        $this->assertSame('Welcome aboard, Rahim Uddin', $mail->build()->subject);
    }

    /** With no row written, the shop keeps sending the email it always sent. */
    public function test_the_blade_view_is_used_when_nothing_has_been_written(): void
    {
        $user = User::factory()->create(['name' => 'Rahim Uddin']);

        $this->assertStringContainsString(
            'Welcome to',
            (new WelcomeCustomerMail($user))->build()->subject,
        );
    }

    /** Emptying a template is not a decision anybody makes on purpose. */
    public function test_an_emptied_template_falls_back_to_the_blade_view(): void
    {
        $this->template('welcome', 'Welcome', '   ');

        $user = User::factory()->create(['name' => 'Rahim Uddin']);

        $this->assertStringContainsString(
            'Welcome to',
            (new WelcomeCustomerMail($user))->build()->subject,
        );
    }

    /**
     * A placeholder this email cannot supply survives being filled in, and
     * braces in somebody's inbox are worse than wording the shop did not pick.
     */
    public function test_a_template_naming_something_unknown_falls_back(): void
    {
        $this->template('welcome', 'Welcome {customer_name}', '<p>Your order {order_number} is on its way.</p>');

        $user = User::factory()->create(['name' => 'Rahim Uddin']);

        $subject = (new WelcomeCustomerMail($user))->build()->subject;

        $this->assertStringNotContainsString('{', $subject);
        $this->assertStringContainsString('Welcome to', $subject);
    }

    /** The subject is a template too, and it is the half a customer reads first. */
    public function test_the_subject_is_filled_in_as_well_as_the_body(): void
    {
        $this->template(
            'order_placed',
            'Your {shop_name} order {order_number}',
            '<p>Hi {customer_name}, total {order_total}. {order_items} {order_url}</p>',
        );

        $order = $this->order();
        $subject = (new OrderConfirmationMail($order))->build()->subject;

        $this->assertStringContainsString($order->order_number, $subject);
        $this->assertStringNotContainsString('{', $subject);
    }

    /** Multipart still: a templated email keeps its plain-text half. */
    public function test_a_templated_email_still_carries_a_text_part(): void
    {
        $this->template('welcome', 'Welcome', '<h1>Hello</h1><p>Glad to have you.</p>');

        $user = User::factory()->create(['name' => 'Rahim Uddin']);
        $built = (new WelcomeCustomerMail($user))->build();

        $this->assertNotNull($built->textView);
    }

    /**
     * And the address is written out in it, not only linked.
     *
     * This is the fault that reached production. `strip_tags` turns
     * `<a href="https://…/reset">Reset your password</a>` into three words and
     * throws the URL away — an email that cannot be acted on, delivered to
     * precisely the reader with no HTML to fall back on. Nothing caught it,
     * because nothing read the text part.
     */
    public function test_the_text_part_writes_out_the_address_of_every_link(): void
    {
        $this->template(
            'welcome',
            'Welcome',
            '<p>Hi {customer_name},</p><p><a href="{shop_url}">Start shopping</a></p>',
        );

        $user = User::factory()->create(['name' => 'Rahim Uddin']);
        $built = (new WelcomeCustomerMail($user))->build();

        $text = view($built->textView, $built->buildViewData())->render();

        $this->assertStringContainsString('Start shopping', $text);
        $this->assertStringContainsString(url('/'), $text, 'the text part carries no address');
        /* And no markup came through with it. */
        $this->assertStringNotContainsString('<a ', $text);
    }
}
