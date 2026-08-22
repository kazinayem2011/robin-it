<?php

namespace Tests\Feature\Mail;

use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusUpdatedMail;
use App\Mail\WelcomeCustomerMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\BrandDetails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Order confirmation emails were failing for every order: the Blade template
 * echoed $order->shipping_address, which is cast to an array, raising
 * "htmlspecialchars(): argument must be of type string, array given".
 *
 * The send was wrapped in a try/catch that logged a warning and moved on, so
 * nothing surfaced — the customer simply never received an email. These tests
 * render the templates for real so a repeat cannot pass unnoticed.
 */
class OrderMailTest extends TestCase
{
    use RefreshDatabase;

    private function order(?User $user = null): Order
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Intel Core i9-14900KS',
            'slug' => 'intel-core-i9-'.uniqid(),
            'price' => 85000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $order = Order::create([
            'user_id' => $user?->id,
            'order_number' => 'ORD-MAILTEST'.random_int(10, 99),
            'subtotal' => 85000, 'shipping_fee' => 60, 'discount' => 0, 'total' => 85060,
            'status' => 'pending', 'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => [
                'name' => 'Rahim Chowdhury',
                'phone' => '01712345678',
                'street_address' => 'House 45, Road 7, Gulshan 2',
                'city' => 'Dhaka',
                'zone' => 'Gulshan',
            ],
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'price' => 85000, 'quantity' => 1, 'total' => 85000,
        ]);

        return $order->load(['items.product', 'user']);
    }

    /**
     * Send through the array transport and read the plain-text part back.
     * Mailable has no renderText(), and this also proves the text view is
     * actually attached rather than merely compiling on its own.
     */
    private function textPartOf(Mailable $mailable): string
    {
        // These mailables are ShouldQueue; the sync queue runs them through the
        // default mailer, so read that transport rather than a separate instance.
        Mail::to('inbox@example.com')->send($mailable);

        $messages = Mail::getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'Nothing reached the mail transport.');

        // ArrayTransport::messages() hands back a Collection, not an array.
        return (string) $messages->last()->getOriginalMessage()->getTextBody();
    }

    public function test_the_confirmation_email_renders_without_error(): void
    {
        $mailable = new OrderConfirmationMail($this->order(User::factory()->create()));

        // render() is what blew up in production; assertions on the output follow.
        $html = $mailable->render();

        $this->assertNotEmpty($html);
    }

    public function test_the_confirmation_email_shows_a_readable_address(): void
    {
        $mailable = new OrderConfirmationMail($this->order(User::factory()->create()));

        $mailable->assertSeeInHtml('Rahim Chowdhury');
        $mailable->assertSeeInHtml('House 45, Road 7, Gulshan 2');
        $mailable->assertSeeInHtml('01712345678');

        // The array must never be dumped into the message.
        $mailable->assertDontSeeInHtml('Array');
        $mailable->assertDontSeeInHtml('street_address');
    }

    public function test_the_confirmation_email_renders_for_a_guest_order(): void
    {
        // A guest order has no user, so anything reading $order->user->… must not blow up.
        $mailable = new OrderConfirmationMail($this->order(null));

        $mailable->assertSeeInHtml('Rahim Chowdhury');
        $mailable->assertSeeInHtml('01712345678');
    }

    public function test_the_status_update_email_renders(): void
    {
        $order = $this->order(User::factory()->create());
        $order->update(['status' => 'shipped']);

        $mailable = new OrderStatusUpdatedMail($order->fresh()->load('items'));

        $mailable->assertSeeInHtml('House 45, Road 7, Gulshan 2');
        $mailable->assertDontSeeInHtml('Array');
    }

    public function test_order_mail_is_queued_so_checkout_does_not_wait_on_smtp(): void
    {
        $this->assertInstanceOf(
            ShouldQueue::class,
            new OrderConfirmationMail($this->order(User::factory()->create()))
        );
    }

    /**
     * The plain-text parts are separate Blade views, so a syntax error in one
     * fails the send even though the HTML is fine — exactly what a nested inline
     *
     * @if/@endif did here.
     */
    public function test_the_plain_text_part_renders_for_every_mailable(): void
    {
        $order = $this->order(User::factory()->create());

        foreach ([
            new OrderConfirmationMail($order),
            new OrderStatusUpdatedMail($order),
        ] as $mailable) {
            $text = $this->textPartOf($mailable);

            $this->assertNotEmpty($text);
            $this->assertStringContainsString($order->order_number, $text);
            $this->assertStringNotContainsString('@if', $text, 'Blade directives must be compiled, not literal.');
            $this->assertStringNotContainsString('{{', $text);
        }
    }

    public function test_the_plain_text_part_renders_with_a_discount(): void
    {
        $order = $this->order(User::factory()->create());
        $order->forceFill(['discount' => 500, 'coupon_code' => 'SAVE500'])->save();

        $text = $this->textPartOf(new OrderConfirmationMail($order->fresh()->load('items')));

        $this->assertStringContainsString('SAVE500', $text);
        $this->assertStringContainsString('500.00', $text);
    }

    public function test_every_mailable_carries_both_an_html_and_a_text_part(): void
    {
        $order = $this->order(User::factory()->create());

        foreach ([new OrderConfirmationMail($order), new OrderStatusUpdatedMail($order)] as $mailable) {
            $this->assertNotEmpty($mailable->render(), 'HTML part missing');
            $this->assertNotEmpty($this->textPartOf($mailable), 'Plain-text part missing');
        }
    }

    /** The inbox preview line should describe the message, not leak markup. */
    public function test_the_html_carries_a_preheader_and_outlook_fallbacks(): void
    {
        $html = (new OrderConfirmationMail($this->order(User::factory()->create())))->render();

        $this->assertStringContainsString('display:none', $html, 'preheader missing');
        $this->assertStringContainsString('<!--[if mso]>', $html, 'Outlook width fallback missing');
        $this->assertStringContainsString('v:roundrect', $html, 'bulletproof button missing');
        $this->assertStringContainsString('role="presentation"', $html, 'layout should be table-based');
    }

    /**
     * Header and footer are shared partials, so a brand change has to show up in
     * every message rather than only the one that was edited.
     */
    public function test_the_header_and_footer_are_shared_by_every_email(): void
    {
        SiteSetting::set('site_name', 'Shared Brand Co');
        SiteSetting::set('site_hotline', '01888-777666');

        $order = $this->order(User::factory()->create());

        foreach ([
            (new OrderConfirmationMail($order))->render(),
            (new OrderStatusUpdatedMail($order))->render(),
            (new WelcomeCustomerMail(User::factory()->create()))->render(),
        ] as $html) {
            $this->assertStringContainsString('Shared Brand Co', $html);
            $this->assertStringContainsString('01888-777666', $html);
            $this->assertStringContainsString('Support Hotline', $html, 'footer missing');
        }
    }

    public function test_the_header_uses_the_configured_logo(): void
    {
        SiteSetting::set('site_logo', '/storage/uploads/brands/custom-logo.png');

        $html = (new OrderConfirmationMail($this->order(User::factory()->create())))->render();

        $this->assertStringContainsString('custom-logo.png', $html);
        $this->assertStringContainsString('<img', $html);
    }

    /**
     * Email clients have no page context, so a relative src never resolves.
     */
    public function test_the_logo_url_is_absolute(): void
    {
        SiteSetting::set('site_logo', '/images/logo.png');

        $this->assertStringStartsWith('http', BrandDetails::logoUrl());

        $html = (new OrderConfirmationMail($this->order(User::factory()->create())))->render();
        $this->assertStringNotContainsString('src="/images', $html, 'logo src must be absolute');
    }

    public function test_an_absolute_logo_url_is_left_alone(): void
    {
        SiteSetting::set('site_logo', 'https://cdn.example.com/logo.png');

        $this->assertSame('https://cdn.example.com/logo.png', BrandDetails::logoUrl());
    }

    /**
     * Images are blocked by default in many clients, so the logo must carry alt
     * text that reads as the store name.
     */
    /**
     * The logo is embedded in the message rather than linked.
     *
     * A URL only resolves if the site is publicly reachable; with
     * APP_URL=http://localhost:8000 the header rendered blank in a real inbox.
     */
    public function test_the_logo_is_embedded_in_the_message(): void
    {
        SiteSetting::set('site_logo', '/images/logo.png');

        Mail::to('inbox@example.com')->send(
            new OrderConfirmationMail($this->order(User::factory()->create()))
        );

        $message = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = $message->getHtmlBody();

        preg_match('/<img[^>]+src="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'no <img> in the header');
        $this->assertStringStartsWith('cid:', $m[1], 'the logo should be embedded, not linked');

        $images = collect($message->getAttachments())
            ->filter(fn ($a) => str_starts_with($a->getMediaType().'/'.$a->getMediaSubtype(), 'image/'));

        $this->assertCount(1, $images, 'exactly one embedded image expected');
        $this->assertGreaterThan(1000, strlen($images->first()->getBody()), 'embedded image looks empty');
    }

    /** A logo uploaded through the admin lands on the public disk, not public/. */
    public function test_an_uploaded_logo_is_found_and_embedded(): void
    {
        Storage::disk('public')->put(
            'uploads/brands/uploaded-logo.png',
            file_get_contents(public_path('images/logo.png'))
        );

        SiteSetting::set('site_logo', '/storage/uploads/brands/uploaded-logo.png');

        $this->assertNotNull(
            BrandDetails::localLogoPath(),
            'an uploaded logo should resolve to a file on the public disk'
        );

        Mail::to('inbox@example.com')->send(
            new OrderConfirmationMail($this->order(User::factory()->create()))
        );

        $html = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('cid:', $html);
    }

    public function test_a_remote_logo_url_is_linked_rather_than_embedded(): void
    {
        SiteSetting::set('site_logo', 'https://cdn.example.com/logo.png');

        $this->assertNull(BrandDetails::localLogoPath());

        Mail::to('inbox@example.com')->send(
            new OrderConfirmationMail($this->order(User::factory()->create()))
        );

        $html = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('https://cdn.example.com/logo.png', $html);
    }

    public function test_the_logo_has_alt_text_naming_the_store(): void
    {
        SiteSetting::set('site_name', 'Alt Text Store');

        $html = (new OrderConfirmationMail($this->order(User::factory()->create())))->render();

        $this->assertMatchesRegularExpression('/<img[^>]+alt="Alt Text Store"/', $html);
    }

    public function test_clearing_the_logo_falls_back_to_the_wordmark(): void
    {
        SiteSetting::set('site_logo', '');
        SiteSetting::set('site_name', 'Wordmark Store');

        $html = (new OrderConfirmationMail($this->order(User::factory()->create())))->render();

        $this->assertStringNotContainsString('<img', $html, 'no logo configured, so no image');
        $this->assertStringContainsString('Wordmark Store', $html);
    }

    public function test_brand_details_come_from_site_settings(): void
    {
        SiteSetting::set('site_name', 'Test Store BD');
        SiteSetting::set('site_hotline', '01999-000000');

        $html = (new OrderConfirmationMail($this->order(User::factory()->create())))->render();

        // These were hardcoded in the templates, so editing them in the admin
        // had no effect on what customers received.
        $this->assertStringContainsString('Test Store BD', $html);
        $this->assertStringContainsString('01999-000000', $html);
    }

    public function test_placing_an_order_sends_the_confirmation(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test CPU', 'slug' => 'test-cpu-'.uniqid(),
            'price' => 5000, 'stock_quantity' => 10, 'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        Mail::assertQueued(
            OrderConfirmationMail::class,
            fn ($mail) => $mail->hasTo($user->email)
        );
    }
}
