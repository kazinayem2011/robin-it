<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One "buy this many, pay this much each" tier.
 */
class ProductDiscount extends Model
{
    protected $fillable = ['product_id', 'min_quantity', 'price', 'starts_at', 'ends_at'];

    protected $casts = [
        'min_quantity' => 'integer',
        'price' => 'float',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Tiers running right now. A window with no dates is always open, which is
     * what a standing trade price is.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
