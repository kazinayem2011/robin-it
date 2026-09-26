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
            'title' => 'Wanted: '.$name,
            'body' => $this->waiting === 1
                ? 'A customer wants to know when it is back.'
                : "{$this->waiting} customers are waiting for it to come back.",
            'url' => '/admin/stock/requests',
            'icon' => 'stock',
        ];
    }
}
