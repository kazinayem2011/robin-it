<?php

namespace Tests\Feature\Mail;

use App\Mail\BackInStockMail;
use App\Mail\ContactReplyMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusUpdatedMail;
use App\Mail\WelcomeCustomerMail;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
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

    /**
     * `{order_items}` is markup, not words — the shop chooses where the table
     * goes and this draws it, because a rich text editor handed the real
     * markup would take it apart.
     */
    public function test_the_order_items_placeholder_draws_the_real_line_items(): void
    {
        $this->template(
            'order_placed',
            'Order {order_number} received',
            '<p>Hi {customer_name},</p>{order_items}<p>Total: {order_total}</p>',
        );

        $html = $this->render(new OrderConfirmationMail($this->order()));

        $this->assertStringContainsString('ASUS TUF Gaming A15', $html);
        $this->assertStringContainsString('Tk 84,500', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('{order_items}', $html);
    }

    /**
     * Every key the seeder ships is one an email actually reads.
     *
     * Wiring six of seven is exactly the failure this catches, and it is the
     * failure that happened on the texts: the shop rewords the one that was
     * missed, the preview agrees with it, and the customer keeps being sent
     * the old thing with nothing anywhere to say so.
     */
    public function test_no_seeded_template_is_left_unread(): void
    {
        $this->seed(MessageTemplateSeeder::class);

        EmailTemplate::query()->update([
            'subject' => 'পরিবর্তিত বিষয়',
            'body' => '<p>পরিবর্তিত পাঠ।</p>',
        ]);

        $user = User::factory()->create(['name' => 'Rahim Uddin']);
        $product = $this->product();

        $built = [
            'welcome' => (new WelcomeCustomerMail($user))->build(),
            'order_placed' => (new OrderConfirmationMail($this->order()))->build(),
            'order_status' => (new OrderStatusUpdatedMail($this->order()))->build(),
            'back_in_stock' => (new BackInStockMail($product, null, 3))->build(),
            'contact_reply' => (new ContactReplyMail(...$this->enquiry()))->build(),

            /*
             * The two the framework sends. Reached through the notification
             * rather than a mailable, because that is how they are customised
             * — a callback registered in AppServiceProvider.
             */
            'verify_email' => (new VerifyEmail)->toMail($user),
            'password_reset' => (new ResetPassword('a-token'))->toMail($user),
        ];

        foreach ($built as $key => $message) {
            $this->assertSame('পরিবর্তিত বিষয়', $message->subject, $key);
        }
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
}
