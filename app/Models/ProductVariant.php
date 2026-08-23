<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sellable configuration of a product — "32GB / 6000MHz".
 *
 * Price falls back to the parent product when not set, so a variant that only
 * differs by colour does not have to restate the money. Stock is always its own.
 */
class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'name', 'sku', 'options', 'price', 'discount_price',
        'stock_quantity', 'image_url', 'is_active', 'position',
    ];

    protected $casts = [
        'options' => 'array',
        'price' => 'float',
        'discount_price' => 'float',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    protected $appends = ['effective_price', 'has_discount', 'in_stock'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /** List price: the variant's own, or the parent product's when unset. */
    public function getListPriceAttribute(): float
    {
        if ($this->price !== null && (float) $this->price > 0) {
            return (float) $this->price;
        }

        return (float) ($this->product?->price ?? 0);
    }

    /**
     * A discount only counts when it is set, above zero, and below the list
     * price — the same rule Product uses, so both levels agree.
     */
    public function hasDiscount(): bool
    {
        $discount = $this->discount_price;

        if ($discount === null && $this->price === null) {
            // Inheriting price entirely, so inherit the parent's discount too.
            return (bool) $this->product?->hasDiscount();
        }

        return $discount !== null
            && (float) $discount > 0
            && (float) $discount < $this->list_price;
    }

    public function getEffectivePriceAttribute(): float
    {
        if ($this->discount_price === null && $this->price === null && $this->product) {
            return $this->product->effective_price;
        }

        return $this->hasDiscount() ? (float) $this->discount_price : $this->list_price;
    }

    public function getHasDiscountAttribute(): bool
    {
        return $this->hasDiscount();
    }

    public function getInStockAttribute(): bool
    {
        return $this->isInStock();
    }

    public function isInStock(): bool
    {
        return $this->is_active && $this->stock_quantity > 0;
    }

    public function canFulfil(int $quantity): bool
    {
        return $this->is_active && $this->stock_quantity >= $quantity;
    }

    /**
     * Build the display label from the option values, e.g. "32GB / 6000MHz".
     */
    public static function labelFor(?array $options): string
    {
        $values = array_filter(array_map('trim', array_values($options ?? [])), fn ($v) => $v !== '');

        return $values ? implode(' / ', $values) : 'Default';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
