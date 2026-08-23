<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the append-only stock ledger.
 *
 * Nothing updates or deletes these rows — a mistake is corrected by adding
 * another movement, so the history always explains the current balance.
 */
class StockMovement extends Model
{
    /** Units arriving from a supplier. The only way stock enters the shelf. */
    public const PURCHASE = 'purchase';

    /** Units leaving because a customer bought them. */
    public const SALE = 'sale';

    /** Reserved units handed back because the order was cancelled. */
    public const CANCELLATION = 'cancellation';

    /** Delivered units that came back in resellable condition. */
    public const RETURN = 'return';

    /** Units that came back damaged, or were lost/broken in the shop. */
    public const WRITE_OFF = 'write_off';

    /** A counted correction — stock-take, breakage, supplier return. */
    public const ADJUSTMENT = 'adjustment';

    /** Stock moving between the product level and its variants. Always nets to zero. */
    public const CONVERSION = 'conversion';

    /** The balance a product carried before the ledger existed. */
    public const OPENING = 'opening';

    public const TYPES = [
        self::PURCHASE, self::SALE, self::CANCELLATION, self::RETURN,
        self::WRITE_OFF, self::ADJUSTMENT, self::CONVERSION, self::OPENING,
    ];

    /** Human labels for the admin ledger view. */
    public const LABELS = [
        self::PURCHASE => 'Purchase received',
        self::SALE => 'Sold',
        self::CANCELLATION => 'Order cancelled',
        self::RETURN => 'Returned to stock',
        self::WRITE_OFF => 'Written off',
        self::ADJUSTMENT => 'Manual adjustment',
        self::CONVERSION => 'Moved between variants',
        self::OPENING => 'Opening balance',
    ];

    protected $fillable = [
        'product_id', 'product_variant_id', 'quantity', 'type', 'balance_after',
        'reference_type', 'reference_id', 'reason', 'note', 'unit_cost', 'user_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'balance_after' => 'integer',
        'unit_cost' => 'float',
    ];

    protected $appends = ['type_label'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::LABELS[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    /** Movements for one stock unit — a variant if given, else the product itself. */
    public function scopeForUnit($query, int $productId, ?int $variantId = null)
    {
        return $query->where('product_id', $productId)
            ->where('product_variant_id', $variantId);
    }
}
