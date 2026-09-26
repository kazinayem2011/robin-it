<?php

namespace App\Models;

use App\Support\PreorderLedger;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    /** Whether the line waits on a delivery; see getWasPreorderedAttribute(). */
    protected $appends = ['was_preordered', 'waiting_for_stock'];

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
        return app(PreorderLedger::class)->wasPreordered(
            (int) $this->order_id,
            (int) $this->product_id,
            $this->product_variant_id ? (int) $this->product_variant_id : null,
        );
    }

    /**
     * Sent with the line wherever it is serialised, so every screen that lists
     * an order can mark it — the cart, checkout, the customer's orders, the
     * tracking page, the admin — not only the invoice, which alone did.
     */
    public function getWasPreorderedAttribute(): bool
    {
        return $this->wasPreordered();
    }

    /**
     * Owed because more was ordered than was in stock, not a pre-order: the
     * line reads "waiting for stock" wherever it is shown.
     */
    public function waitingForStock(): bool
    {
        return app(PreorderLedger::class)->waitingForStock(
            (int) $this->order_id,
            (int) $this->product_id,
            $this->product_variant_id ? (int) $this->product_variant_id : null,
        );
    }

    public function getWaitingForStockAttribute(): bool
    {
        return $this->waitingForStock();
    }

    /** What an email or invoice says about a line that ships later. */
    public function owedLabel(): ?string
    {
        if (! $this->wasPreordered()) {
            return null;
        }

        return $this->waitingForStock()
            ? 'Waiting for stock — ships when the next delivery arrives'
            : 'Pre-order — ships when the delivery arrives';
    }

    /** The physical units handed over for this line, where they are tracked. */
    public function serials()
    {
        return $this->hasMany(ProductSerial::class);
    }
}
