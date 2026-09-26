<?php

namespace Tests\Feature\Stock;

use App\Mail\BackInStockMail;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockNotification;
use App\Models\User;
use App\Services\SmsService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * "Notify me" with a mobile number instead of an email address.
 *
 * Most accounts here are a mobile number and a guest may have no address, so
 * the list takes either, and a request by number is told by text.
 */
class BackInStockByTextTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'GPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'RTX 4090',
            'slug' => 'rtx-4090-'.uniqid(),
            'price' => 250000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    private function ask(Product $product, string $contact)
    {
        return $this->postJson('/api/stock-notifications', [
            'product_id' => $product->id,
            'contact' => $contact,
        ]);
    }

    private function restock(Product $product): void
    {
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 3]]);
    }

    public function test_a_mobile_number_is_taken_and_stored_in_one_form(): void
    {
        $product = $this->product();

        $this->ask($product, '+880 1711-223344')
            ->assertStatus(201)
            ->assertJsonPath('message', "We'll text 01711223344 as soon as it's back.");

        $this->assertDatabaseHas('stock_notifications', [
            'product_id' => $product->id,
            'phone' => '01711223344',
            'email' => null,
        ]);
    }

    public function test_the_same_number_written_differently_is_one_request(): void
    {
        $product = $this->product();

        $this->ask($product, '01711223344')->assertStatus(201);
        $this->ask($product, '1711 223344')->assertStatus(201);

        $this->assertSame(1, StockNotification::count());
    }

    public function test_an_email_in_the_same_box_is_still_an_email(): void
    {
        $product = $this->product();

        $this->ask($product, 'Shopper@Example.com')->assertStatus(201);

        $this->assertDatabaseHas('stock_notifications', [
            'email' => 'shopper@example.com',
            'phone' => null,
        ]);
    }

    public function test_something_that_is_neither_is_refused(): void
    {
        $product = $this->product();

        $this->ask($product, '12345')->assertStatus(422)->assertJsonPath('message', 'Enter an email address, or an 11-digit mobile number such as 01711223344.');
        $this->ask($product, '')->assertStatus(422);

        $this->assertSame(0, StockNotification::count());
    }

    public function test_a_customer_signed_in_with_only_a_number_can_ask(): void
    {
        $product = $this->product();
        $user = User::factory()->create(['email' => null, 'phone' => '01811223344']);

        $this->actingAs($user)->ask($product, '01811223344')->assertStatus(201);

        $this->assertDatabaseHas('stock_notifications', [
            'phone' => '01811223344',
            'user_id' => $user->id,
        ]);
    }

    public function test_the_number_is_texted_when_stock_returns_and_the_address_is_emailed(): void
    {
        Mail::fake();
        $product = $this->product();
        $this->ask($product, '01711223344');
        $this->ask($product, 'one@example.com');

        $this->mock(SmsService::class, function (MockInterface $sms) {
            $sms->shouldReceive('sendEvent')
                ->once()
                ->withArgs(fn ($event, $to, $text) => $event === 'back_in_stock'
                    && $to === '01711223344'
                    && str_contains($text, 'RTX 4090')
                    && SmsService::hasBengali($text))
                ->andReturnTrue();
        });

        $this->restock($product);

        Mail::assertSent(BackInStockMail::class, 1);
        $this->assertSame(0, StockNotification::pending()->count());
    }

    /* Texts switched off, or the gateway down: still waiting, not "told". */
    public function test_a_text_that_did_not_go_leaves_the_request_waiting(): void
    {
        $product = $this->product();
        $this->ask($product, '01711223344');

        $this->mock(SmsService::class, function (MockInterface $sms) {
            $sms->shouldReceive('sendEvent')->once()->andReturnFalse();
        });

        $this->restock($product);

        $this->assertSame(1, StockNotification::pending()->count());
    }
}
