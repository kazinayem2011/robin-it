<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How much of one thing sits at one branch.
 *
 * The cached balance of the ledger rows for that (product, option, branch)
 * combination — the same arrangement products already use, one level down.
 */
class ProductStock extends Model
{
    protected $table = 'product_stock';

    protected $fillable = [
        'product_id', 'product_variant_id', 'store_id', 'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeForUnit($query, int $productId, ?int $variantId = null)
    {
        return $query->where('product_id', $productId)
            ->where('product_variant_id', $variantId);
    }

    public function scopeInStock($query)
    {
        return $query->where('quantity', '>', 0);
    }
}
