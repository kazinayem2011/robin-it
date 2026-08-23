<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\BrandDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "The thing you asked about is back."
 *
 * Not queued itself: it is sent from NotifyBackInStock, which is already a
 * queued job. Queueing it again would put every individual email back on the
 * queue and lose the ordering that lets a partial failure be resumed.
 */
class BackInStockMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Product $product,
        public ?ProductVariant $variant = null,
        public int $available = 0,
    ) {}

    public function build()
    {
        $name = $this->variant
            ? "{$this->product->name} ({$this->variant->name})"
            : $this->product->name;

        return $this->subject("Back in stock: {$name} — ".BrandDetails::all()['name'])
            ->view('emails.stock.back-in-stock')
            ->text('emails.text.stock.back-in-stock')
            ->with([
                'displayName' => $name,
                'available' => $this->available,
                'price' => $this->variant?->effective_price ?? $this->product->effective_price,
                'url' => rtrim(config('app.url'), '/').'/products/'.$this->product->slug,
            ]);
    }
}
