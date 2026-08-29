<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A delivery from a supplier. Receiving one is the only way stock enters.
 */
class StockReceipt extends Model
{
    protected $fillable = [
        'reference', 'supplier_id', 'supplier_name', 'invoice_number', 'received_on',
        'note', 'total_cost', 'total_quantity', 'user_id',
        'purchase_order_id', ];

    protected $casts = [
        'received_on' => 'date',
        'total_cost' => 'float',
        'total_quantity' => 'integer',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(StockReceiptItem::class);
    }

    public function movements()
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The order this delivery was against, where it was against one. */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
