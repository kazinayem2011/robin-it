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

    /** Unit price the shopper pays, from whichever level owns the price. */
    public function unitPrice(): float
    {
        return (float) ($this->variant?->effective_price ?? $this->product?->effective_price ?? 0);
    }

    public function displayName(): string
    {
        $name = $this->product?->name ?? 'Product';

        return $this->variant ? "{$name} ({$this->variant->name})" : $name;
    }
}
