<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_variant_id',
        'quantity', 'quantity_received', 'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'quantity_received' => 'integer',
        'unit_cost' => 'float',
    ];

    protected $appends = ['outstanding'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** What the supplier still owes on this line. Never negative: an
     * over-delivery is the supplier's generosity, not a debt the other way. */
    public function getOutstandingAttribute(): int
    {
        return max(0, $this->quantity - $this->quantity_received);
    }

    public function getDisplayNameAttribute(): string
    {
        $name = $this->product?->name ?? 'Removed product';

        return $this->variant ? "{$name} ({$this->variant->name})" : $name;
    }
}
