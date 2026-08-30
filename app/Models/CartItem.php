<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id', 'product_variant_id', 'quantity'];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The stock unit this line draws from — the chosen option when there is one,
     * otherwise the product itself.
     */
    public function stockUnit(): Product|ProductVariant|null
    {
        return $this->variant ?: $this->product;
    }

    /**
     * Unit price the shopper pays, from whichever level owns the price.
     *
     * On a plain product the quantity matters: buy-more-pay-less tiers are
     * priced per line, so ten cables cost the ten-up rate each. A variant
     * carries its own price and its own shelf, so a tier on the parent cannot
     * be applied to it without claiming a discount the option never had.
     */
    public function unitPrice(): float
    {
        if ($this->variant) {
            return (float) $this->variant->effective_price;
        }

        if (! $this->product) {
            return 0.0;
        }

        return $this->product->priceForQuantity((int) $this->quantity);
    }

    public function displayName(): string
    {
        $name = $this->product?->name ?? 'Product';

        return $this->variant ? "{$name} ({$this->variant->name})" : $name;
    }
}
