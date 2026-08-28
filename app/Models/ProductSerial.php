<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical unit, and where it has been.
 */
class ProductSerial extends Model
{
    public const IN_STOCK = 'in_stock';

    public const SOLD = 'sold';

    public const FAULTY = 'faulty';

    /*
     * Three states, because a physical unit is only ever in three places: on a
     * shelf, with a customer, or written off. "Returned" was a fourth that
     * nothing moved a unit out of — a working unit that came back was stranded
     * there, invisible to the next sale, while the stock count said it was
     * available. A resellable return goes back to the shelf, because that is
     * where it is; a damaged one is written off.
     */
    public const STATUSES = [
        self::IN_STOCK => 'On the shelf',
        self::SOLD => 'Sold',
        self::FAULTY => 'Faulty / written off',
    ];

    protected $fillable = [
        'product_id', 'product_variant_id', 'serial', 'store_id', 'status',
        'stock_receipt_id', 'order_id', 'order_item_id', 'sold_at',
        'warranty_until', 'note',
    ];

    protected $appends = ['status_label', 'under_warranty'];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'warranty_until' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /**
     * Null rather than false when nothing is known.
     *
     * A unit that has never been sold has no warranty to be inside or outside
     * of, and saying "not under warranty" about one still on the shelf would
     * be answering a question nobody asked.
     */
    public function getUnderWarrantyAttribute(): ?bool
    {
        if ($this->status !== self::SOLD || ! $this->warranty_until) {
            return null;
        }

        return $this->warranty_until->endOfDay()->isFuture();
    }

    /** Trimmed and upper-cased: a serial is a code, not a sentence. */
    public static function normalise(?string $serial): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $serial));
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', self::IN_STOCK);
    }
}
