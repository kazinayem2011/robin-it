<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_variant_id', 'product_name',
        'variant_name', 'price', 'quantity', 'returned_quantity', 'total',
    ];

    protected $casts = [
        'price' => 'float',
        'total' => 'float',
        'quantity' => 'integer',
        'returned_quantity' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** What the customer sees on the invoice, including the option they chose. */
    public function getDisplayNameAttribute(): string
    {
        return $this->variant_name
            ? "{$this->product_name} ({$this->variant_name})"
            : (string) $this->product_name;
    }

    /** Units of this line that have not yet come back. */
    public function getReturnableQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }
}
