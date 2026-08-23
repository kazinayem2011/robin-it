<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A request to be told when something sold out comes back.
 */
class StockNotification extends Model
{
    protected $fillable = [
        'product_id', 'product_variant_id', 'email', 'user_id', 'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Still waiting to hear. */
    public function scopePending($query)
    {
        return $query->whereNull('notified_at');
    }

    /** Requests for one stock unit — a variant if given, else the product. */
    public function scopeForUnit($query, int $productId, ?int $variantId = null)
    {
        return $query->where('product_id', $productId)
            ->where('product_variant_id', $variantId);
    }

    public function displayName(): string
    {
        $name = $this->product?->name ?? 'This product';

        return $this->variant ? "{$name} ({$this->variant->name})" : $name;
    }
}
