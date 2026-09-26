<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * A customer asked to be told when something sold out comes back.
 *
 * The request was stored and acted on, but the shop was never told one had
 * been made: the clearest sign of what to order next arrived in silence. Sent
 * to whoever keeps the stock, as a low shelf is, with the queue it joined.
 */
class StockRequested extends ShopNotification
{
    public function __construct(
        public readonly Product $product,
        public readonly ?ProductVariant $variant,
        public readonly int $waiting,
    ) {}

    public function payload(object $notifiable): array
    {
        $name = $this->product->name.($this->variant ? ' — '.$this->variant->name : '');

        return [
            'kind' => 'stock.requested',
            // Named as the menu names it, so the alert and the page match.
            'title' => 'Notify-me request: '.$name,
            'body' => $this->waiting === 1
                ? 'A customer asked to be told when it is back in stock.'
                : "A customer asked to be told when it is back in stock — {$this->waiting} waiting now.",
            'url' => '/admin/stock/requests',
            'icon' => 'stock',
        ];
    }
}
