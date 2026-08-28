<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_variant_id', 'product_name',
        'variant_name', 'price', 'unit_cost', 'quantity', 'returned_quantity', 'total',
    ];

    protected $casts = [
        'price' => 'float',
        'unit_cost' => 'float',
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

    /**
     * What this line cost the shop, or null when the cost is not known.
     *
     * Null rather than zero: a line whose product never came in through a
     * delivery has no cost, and treating that as free would report the whole
     * sale as profit.
     */
    public function getCostTotalAttribute(): ?float
    {
        return $this->unit_cost === null
            ? null
            : round((float) $this->unit_cost * (int) $this->quantity, 2);
    }

    /** What this line earned, before delivery and any discount. */
    public function getGrossProfitAttribute(): ?float
    {
        return $this->cost_total === null
            ? null
            : round((float) $this->total - $this->cost_total, 2);
    }

    /** Units of this line that have not yet come back. */
    public function getReturnableQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }

    /**
     * Whether this line was sold ahead of the delivery.
     *
     * Read from the ledger rather than stored: the SALE movement this order
     * wrote carries the balance it left behind, and a balance below zero is
     * exactly what "units owed" means. Nothing has to be recorded twice, and a
     * line stays correctly marked even after the delivery lands and the balance
     * climbs back up.
     */
    public function wasPreordered(): bool
    {
        $balance = StockMovement::where('reference_type', Order::class)
            ->where('reference_id', $this->order_id)
            ->where('type', StockMovement::SALE)
            ->where('product_id', $this->product_id)
            ->when(
                $this->product_variant_id,
                fn ($q) => $q->where('product_variant_id', $this->product_variant_id),
                fn ($q) => $q->whereNull('product_variant_id')
            )
            ->orderByDesc('id')
            ->value('balance_after');

        return $balance !== null && (int) $balance < 0;
    }

    /** The physical units handed over for this line, where they are tracked. */
    public function serials()
    {
        return $this->hasMany(ProductSerial::class);
    }
}
