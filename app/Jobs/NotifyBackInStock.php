<?php

namespace App\Jobs;

use App\Mail\BackInStockMail;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockNotification;
use App\Services\SmsService;
use App\Support\BrandDetails;
use App\Support\SmsTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tell everyone waiting that something is back.
 *
 * Queued, because a delivery of thirty lines should not sit waiting on thirty
 * batches of mail, and because a stock movement must never fail on account of
 * an unreachable SMTP server.
 *
 * By email or by text, whichever the request carries.
 */
class NotifyBackInStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $productId,
        public ?int $variantId = null,
    ) {}

    public function handle(SmsService $sms): void
    {
        $product = Product::find($this->productId);

        if (! $product || ! $product->is_active) {
            return;
        }

        $variant = $this->variantId ? ProductVariant::find($this->variantId) : null;

        // The stock may have gone again between the delivery and this job
        // running. Telling someone it is back when it is not is worse than
        // saying nothing, so check the shelf as it stands now.
        $available = (int) ($variant?->stock_quantity ?? $product->stock_quantity);

        if ($available <= 0) {
            return;
        }

        $waiting = StockNotification::forUnit($this->productId, $this->variantId)
            ->pending()
            ->get();

        $text = null;

        foreach ($waiting as $request) {
            try {
                if ($request->email) {
                    Mail::to($request->email)->send(
                        new BackInStockMail($product, $variant, $available)
                    );
                } elseif ($request->phone) {
                    $text ??= SmsTemplates::backInStock($product, $variant, BrandDetails::name());

                    /*
                     * Left waiting when the text did not go — texts switched
                     * off, or the gateway down — rather than marked as told.
                     * Next time the stock comes back it is tried again.
                     */
                    if (! $sms->sendEvent('back_in_stock', $request->phone, $text)) {
                        continue;
                    }
                } else {
                    continue;
                }

                // Marked before the next send so a failure partway through a
                // long list cannot re-mail everyone who already heard.
                $request->update(['notified_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning('Could not send a back-in-stock message: '.$e->getMessage(), [
                    'product_id' => $this->productId,
                    'variant_id' => $this->variantId,
                    'request_id' => $request->id,
                ]);
            }
        }
    }
}
