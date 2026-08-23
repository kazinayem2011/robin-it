<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReceiptItem extends Model
{
    protected $fillable = [
        'stock_receipt_id', 'product_id', 'product_variant_id', 'quantity', 'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'float',
    ];

    public function receipt()
    {
        return $this->belongsTo(StockReceipt::class, 'stock_receipt_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
