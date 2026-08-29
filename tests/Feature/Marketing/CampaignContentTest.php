<?php

namespace Tests\Feature\Marketing;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\SmsService;
use App\Support\CampaignContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Dropping a product or a code into a promotion.
 *
 * Typing "RTX 4090, Tk 2,45,000, was Tk 2,60,000" by hand is how a campaign
 * goes out with last month's price on it. A token is written instead, and the
 * price is read when it sends from the same row the shop sells from.
 *
 * The message box holds the token rather than styled HTML for two reasons: the
 * sanitiser strips inline styles on the way in, correctly, and a message
 * written as HTML for an email is unreadable when the same campaign goes out as
 * a text.
 */
class CampaignContentTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Coupon $coupon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'GPU', 'slug' => 'gpu', 'is_active' => true])->id,
            'name' => 'RTX 4090', 'slug' => 'rtx-4090',
            'price' => 20000, 'discount_price' => 18000,
            'stock_quantity' => 5, 'is_active' => true,
        ]);

        $this->coupon = Coupon::create([
            'code' => 'EID15', 'discount_type' => 'percent',
            'discount_value' => 15, 'is_active' => true,
        ]);
    }

    // --- what a phone gets --------------------------------------------------

    public function test_a_product_becomes_its_name_price_and_link(): void
    {
        $text = CampaignContent::text('Look: [[product:rtx-4090]]');

        $this->assertStringContainsString('RTX 4090', $text);
        $this->assertStringContainsString('Tk 18,000', $text);
        $this->assertStringContainsString('was Tk 20,000', $text);
        $this->assertStringContainsString('/products/rtx-4090', $text);
    }

    public function test_a_product_at_full_price_does_not_pretend_to_be_reduced(): void
    {
        $this->product->update(['discount_price' => null]);

        $text = CampaignContent::text('[[product:rtx-4090]]');

        $this->assertStringContainsString('Tk 20,000', $text);
        $this->assertStringNotContainsString('was', $text);
    }

    public function test_a_coupon_becomes_the_code_and_what_it_takes_off(): void
    {
        $text = CampaignContent::text('[[coupon:EID15]]');

        $this->assertStringContainsString('EID15', $text);
        $this->assertStringContainsString('15% off', $text);
    }

    public function test_a_fixed_coupon_reads_in_taka(): void
    {
        Coupon::create([
            'code' => 'FLAT500', 'discount_type' => 'fixed',
            'discount_value' => 500, 'is_active' => true,
        ]);

        $this->assertStringContainsString('Tk 500 off', CampaignContent::text('[[coupon:FLAT500]]'));
    }

    /** A product and a code together, with the price after the code worked out. */
    public function test_a_deal_prices_the_product_after_the_code(): void
    {
        $text = CampaignContent::text('[[deal:rtx-4090:EID15]]');

        // 15% off 18,000 is 2,700.
        $this->assertStringContainsString('Tk 15,300', $text);
        $this->assertStringContainsString('with code EID15', $text);
    }

    /**
     * The coupon's own ceiling is honoured, because the price is asked of the
     * coupon rather than worked out again here. A "20% off up to Tk 2,000" code
     * advertised as Tk 9,000 off is a promise the checkout will refuse to keep.
     */
    public function test_a_deal_respects_the_coupons_maximum(): void
    {
        $this->coupon->update(['discount_value' => 50, 'max_discount' => 1000]);

        $text = CampaignContent::text('[[deal:rtx-4090:EID15]]');

        // Half of 18,000 is 9,000, but the code never gives more than 1,000.
        $this->assertStringContainsString('Tk 17,000', $text);
        $this->assertStringNotContainsString('Tk 9,000', $text);
    }

    // --- what an inbox gets -------------------------------------------------

    public function test_the_email_version_carries_the_shops_own_markup(): void
    {
        $html = CampaignContent::html('[[product:rtx-4090]]');

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Shop now', $html);
        $this->assertStringContainsString('line-through', $html);
    }

    /**
     * The same campaign, both ways. Building the markup at insert time — as the
     * obvious version does — leaves HTML in a text message the moment somebody
     * switches the channel after writing it.
     */
    public function test_the_same_body_reads_properly_on_both_channels(): void
    {
        $body = 'Our sale: [[product:rtx-4090]]';

        $this->assertStringContainsString('<table', CampaignContent::html($body));
        $this->assertStringNotContainsString('<', CampaignContent::text($body));
    }

    public function test_markup_never_reaches_a_text_message(): void
    {
        $text = CampaignContent::text('<p>Hello <strong>you</strong></p> [[coupon:EID15]]');

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('Hello you', $text);
    }

    // --- when it points at nothing -------------------------------------------

    public function test_a_missing_product_is_named_before_sending(): void
    {
        $this->assertSame(
            ['product "gone-forever"'],
            CampaignContent::missing('[[product:gone-forever]]')
        );
    }

    public function test_a_delisted_product_counts_as_missing(): void
    {
        $this->product->update(['is_active' => false]);

        $this->assertNotEmpty(CampaignContent::missing('[[product:rtx-4090]]'));
    }

    public function test_a_body_with_no_tokens_is_left_exactly_alone(): void
    {
        $this->assertSame([], CampaignContent::missing('Just words.'));
        $this->assertSame('Just words.', CampaignContent::text('Just words.'));
    }

    /**
     * The whole list would otherwise receive a sentence with a hole in it.
     */
    public function test_a_campaign_pointing_at_nothing_cannot_be_sent(): void
    {
        Queue::fake();
        User::factory()->create(['role' => 'customer', 'accepts_marketing' => true, 'is_active' => true]);

        $campaign = Campaign::create([
            'title' => 'Sale', 'subject' => 'Sale', 'body' => 'Look: [[product:gone-forever]]',
            'channel' => 'email', 'audience' => 'customers', 'status' => 'draft',
        ]);

        $this->expectExceptionMessage('no longer available: product "gone-forever"');
        app(CampaignService::class)->send($campaign);
    }

    // --- what it costs --------------------------------------------------------

    /**
     * The estimate has to price the rendered message, not the token.
     *
     * "[[product:rtx-4090]]" is twenty characters; what it becomes is a name,
     * two prices and a URL. Billing is by the part, so pricing the token would
     * quote a fraction of the real bill.
     *
     * Asserted against the rendered body rather than as "more than the short
     * one" — that comparison passed by accident while the block still contained
     * an em dash, and stopped meaning anything the moment that was fixed.
     */
    public function test_the_estimate_prices_the_rendered_message_not_the_token(): void
    {
        User::factory()->create([
            'role' => 'customer', 'accepts_marketing' => true,
            'is_active' => true, 'phone' => '01712345678',
        ]);

        // Long enough that the rendered block cannot fit in one part, so the
        // difference between pricing the token and pricing the message shows.
        $this->product->update([
            'name' => str_repeat('Graphics Card Model ', 6).'X',
        ]);

        $campaigns = app(CampaignService::class);
        $body = 'Sale on. [[product:rtx-4090]]';

        $estimate = $campaigns->estimate(new Campaign([
            'channel' => 'sms', 'audience' => 'customers', 'body' => $body,
        ]));

        $rendered = $campaigns->smsBody(new Campaign([
            'channel' => 'sms', 'audience' => 'customers', 'body' => $body,
        ]));

        $this->assertGreaterThan(1, SmsService::parts($rendered), 'test fixture is too short to prove anything');
        $this->assertSame(
            SmsService::parts($rendered),
            $estimate['sms_parts'],
            'The estimate must price what actually goes out, not the token that stands in for it.'
        );
        $this->assertGreaterThan(
            SmsService::parts($body),
            $estimate['sms_parts'],
            'Pricing the token rather than the message quotes a fraction of the bill.'
        );
    }

    // --- the pickers -----------------------------------------------------------

    public function test_the_picker_offers_products_and_running_codes(): void
    {
        Coupon::create([
            'code' => 'EXPIRED', 'discount_type' => 'percent', 'discount_value' => 50,
            'is_active' => true, 'expires_at' => now()->subDay(),
        ]);

        $data = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/campaigns/pickers')
            ->assertOk()
            ->json('data');

        $this->assertSame('RTX 4090', $data['products'][0]['name']);
        $this->assertContains('EID15', collect($data['coupons'])->pluck('code'));
        // A code somebody cannot use is a complaint waiting at the counter.
        $this->assertNotContains('EXPIRED', collect($data['coupons'])->pluck('code'));
    }

    /**
     * The preview is rendered by the same code that sends, so it cannot drift
     * from what lands.
     */
    public function test_the_preview_returns_both_rendered_versions(): void
    {
        $data = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/campaigns/preview', [
                'title' => 'Sale', 'subject' => 'Sale',
                'body' => 'Salam {name}. [[product:rtx-4090]]',
                'channel' => 'both', 'audience' => 'all',
            ])
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('<table', $data['html']);
        $this->assertStringNotContainsString('<', $data['text']);
        // Personalised, so somebody can see the name land where they put it.
        $this->assertStringContainsString('Salam Rahim', $data['text']);
        $this->assertSame([], $data['missing']);
    }
}
