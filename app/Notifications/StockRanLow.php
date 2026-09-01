<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * A product has fallen to the level at which it should be reordered.
 *
 * Sent when it crosses, not while it sits below: a shelf that is low all week
 * would otherwise send a message every time anything moved, and a bell that
 * cries every hour is a bell nobody reads.
 */
class StockRanLow extends ShopNotification
{
    public function __construct(
        public readonly Product $product,
        public readonly ?ProductVariant $variant,
        public readonly int $remaining,
    ) {}

    public function payload(object $notifiable): array
    {
        $name = $this->product->name.($this->variant ? ' — '.$this->variant->name : '');

        return [
            'kind' => 'stock.low',
            'title' => 'Low stock: '.$name,
            'body' => $this->remaining === 0
                ? 'None left on the shelf.'
                : $this->remaining.' left, at or below the reorder level.',
            'url' => '/admin/stock?search='.urlencode($this->product->name),
            'icon' => 'stock',
        ];
    }
}
