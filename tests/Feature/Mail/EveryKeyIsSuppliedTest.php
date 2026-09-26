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
use App\Models\Courier;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\MessageKeys;
use App\Support\SmsTemplates;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * That every key a shop can write is one a message reads, and fills entirely.
 *
 * Two faults of the same shape, and neither is visible from outside. A key can
 * ship with a row a shop edits and no sender reading it — which is how
 * `payment_due` went out, six of seven looking exactly like seven of seven.
 * And a key can be read by a sender that supplies the wrong names, which
 * leaves a placeholder standing, sends the wording back to the default, and so
 * ignores what the shop wrote from then on — correctly, permanently, and with
 * a preview that goes on showing the new words.
 *
 * So each key's template is rewritten here to name every variable it declares
 * and nothing else. If any of them survives the real message being built, the
 * fallback takes over, the marker never appears, and this fails.
 */
class EveryKeyIsSuppliedTest extends TestCase
{
    use RefreshDatabase;

    /** A body that uses every declared variable, so one unsupplied is fatal. */
    private function bodyNaming(array $variables): string
    {
        return 'MARKER '.implode(' ', array_map(fn ($v) => '{'.$v.'}', $variables));
    }

    private function order(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'ORD-'.Str::random(6),
            'session_id' => Str::random(40),
            'status' => 'pending',
            'subtotal' => 84500, 'shipping_fee' => 0, 'discount' => 0, 'total' => 84500,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim Uddin', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ], $overrides));

        $order->items()->create([
            'product_name' => 'ASUS TUF Gaming A15',
            'price' => 84500, 'quantity' => 1, 'total' => 84500,
        ]);

        return $order->fresh()->load('items');
    }

    public function test_every_email_key_is_read_and_filled_completely(): void
    {
        foreach (MessageKeys::EMAIL_WRITTEN as $key) {
            $variables = MessageKeys::email($key);
            EmailTemplate::updateOrCreate(['key' => $key], [
                'name' => $key, 'group' => 'Test',
                'subject' => 'MARKER '.$key,
                'body' => '<p>'.$this->bodyNaming($variables).'</p>',
                'variables' => $variables,
            ]);
        }

        foreach ($this->everyEmail() as $key => $message) {
            $this->assertSame(
                'MARKER '.$key,
                $message->subject,
                "{$key}: the template was not used, so a variable it declares is not supplied",
            );
        }
    }

    /**
     * And the four that are not written by a template keep everything.
     *
     * The inverse is worth asserting because it is what went wrong. Wiring
     * these up looked like more of a good thing and was a downgrade: a
     * template replaced the whole email, so a receipt carrying line items, an
     * address, a payment method and a tracking button became three sentences
     * — and a password reset lost the link out of its plain-text half. A row
     * exists for each so the screen can show the wording; nothing reads it.
     */
    public function test_a_designed_email_is_not_replaced_by_a_template(): void
    {
        foreach (MessageKeys::EMAIL as $key => $variables) {
            EmailTemplate::updateOrCreate(['key' => $key], [
                'name' => $key, 'group' => 'Test',
                'subject' => 'MARKER '.$key,
                'body' => '<p>'.$this->bodyNaming($variables).'</p>',
                'variables' => $variables,
            ]);
        }

        $order = $this->order();
        $address = $order->shipping_address;
        $user = User::factory()->create(['name' => 'Rahim Uddin']);

        $confirmation = (new OrderConfirmationMail($order))->build();
        $html = $confirmation->render();

        $this->assertStringNotContainsString('MARKER', $confirmation->subject);
        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString($address['phone'], $html);
        /* The Outlook-proof button, which is markup a template cannot hold. */
        $this->assertStringContainsString('v:roundrect', $html);

        $status = (new OrderStatusUpdatedMail($order))->build();
        $this->assertStringNotContainsString('MARKER', $status->subject);

        foreach ([
            'verify_email' => (new VerifyEmail)->toMail($user),
            'password_reset' => (new ResetPassword('a-token'))->toMail($user),
        ] as $key => $message) {
            $this->assertStringNotContainsString('MARKER', $message->subject, $key);
            $this->assertStringContainsString('Robins Computer', $message->subject, $key);
        }
    }

    public function test_every_text_key_is_read_and_filled_completely(): void
    {
        foreach (MessageKeys::SMS as $key => $variables) {
            SmsTemplate::updateOrCreate(['key' => $key], [
                'name' => $key, 'group' => 'Test',
                'body' => $this->bodyNaming($variables),
                'variables' => $variables,
            ]);
        }

        foreach ($this->everyText() as $key => $message) {
            $this->assertStringStartsWith(
                'MARKER',
                (string) $message,
                "{$key}: the template was not used, so a variable it declares is not supplied",
            );
            $this->assertStringNotContainsString('{', (string) $message, $key);
        }
    }

    /**
     * Built, not sent. The keys here must be exactly MessageKeys::EMAIL —
     * a key with nothing behind it is the fault this exists for.
     *
     * @return array<string, Mailable|MailMessage>
     */
    private function everyEmail(): array
    {
        $user = User::factory()->create(['name' => 'Rahim Uddin']);

        $category = Category::create(['name' => 'Laptop', 'slug' => 'laptop-'.Str::random(6), 'is_active' => true]);
        $product = Product::create([
            'name' => 'ASUS TUF Gaming A15', 'slug' => 'asus-'.Str::random(6),
            'category_id' => $category->id, 'price' => 84500,
            'stock_quantity' => 3, 'is_active' => true,
        ]);

        $enquiry = ContactMessage::create([
            'name' => 'Rahim Uddin', 'email' => 'rahim@example.com',
            'subject' => 'Is the RTX 4060 model in stock?', 'message' => 'Asking about stock.',
        ]);
        $reply = ContactReply::create([
            'contact_message_id' => $enquiry->id,
            'author_name' => 'Nazmul', 'body' => 'Yes, three in Uttara.',
        ]);

        $built = [
            'welcome' => (new WelcomeCustomerMail($user))->build(),
            'back_in_stock' => (new BackInStockMail($product, null, 3))->build(),
            'contact_reply' => (new ContactReplyMail($enquiry, $reply))->build(),
        ];

        /* The sets must match; the order they are listed in does not. */
        $this->assertEqualsCanonicalizing(
            MessageKeys::EMAIL_WRITTEN,
            array_keys($built),
            'an email key has no message behind it, or a message has no key',
        );

        return $built;
    }

    /** @return array<string, string|null> */
    private function everyText(): array
    {
        $shop = 'Robins Computer';
        $order = $this->order();

        $built = [
            'order_placed' => SmsTemplates::orderPlaced($order, $shop),
            'account_created' => SmsTemplates::accountCreated($shop),
            'contact_reply' => SmsTemplates::contactReply('তিনটি আছে।', $shop, '01720000000'),
            // The same builder, given an answer too long to text.
            'contact_reply_call' => SmsTemplates::contactReply(str_repeat('অনেক লম্বা উত্তর। ', 20), $shop, '01720000000'),
            'order_updated' => SmsTemplates::orderUpdated($order, $shop),
            'payment_due' => SmsTemplates::paymentDue($order, 84500, $shop),
            'refund' => SmsTemplates::refundIssued($order, 12000, $shop),
            'back_in_stock' => SmsTemplates::backInStock(
                (new Product)->forceFill(['name' => 'ASUS TUF Gaming A15', 'slug' => 'asus-tuf-gaming-a15']),
                null,
                $shop,
            ),
        ];

        /* The courier is a declared variable, so the order has to carry one. */
        $courier = Courier::firstOrCreate(['slug' => 'pathao'], ['name' => 'Pathao', 'is_active' => true]);
        $order->courier()->associate($courier)->save();

        foreach (['processing', 'shipped', 'delivered', 'cancelled', 'returned'] as $status) {
            $order->status = $status;
            $built[$status] = SmsTemplates::statusChanged($order, $shop);
        }

        $this->assertEqualsCanonicalizing(
            array_keys(MessageKeys::SMS),
            array_keys($built),
            'a text key has no message behind it, or a message has no key',
        );

        return $built;
    }
}
