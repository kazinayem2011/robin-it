<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the shop has asked a supplier for.
 *
 * See the migration for why. In short: receipts record what arrived and nothing
 * recorded what was asked for, so a short shipment was invisible and "when is
 * that back in stock" had no answer.
 */
class PurchaseOrder extends Model
{
    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const PARTIAL = 'partial';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::SENT => 'With the supplier',
        self::PARTIAL => 'Part delivered',
        self::RECEIVED => 'Delivered',
        self::CANCELLED => 'Cancelled',
    ];

    /** The states in which units are genuinely expected to turn up. */
    public const OPEN = [self::SENT, self::PARTIAL];

    protected $fillable = [
        'reference', 'supplier_id', 'supplier_name', 'store_id', 'status',
        'expected_on', 'note', 'user_id', 'ordered_by_name', 'sent_at',
        'total_quantity', 'total_cost',
    ];

    protected $casts = [
        'expected_on' => 'date',
        'sent_at' => 'datetime',
        'total_quantity' => 'integer',
        'total_cost' => 'float',
    ];

    protected $appends = ['status_label', 'outstanding'];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /** How many units the supplier still owes on this order. */
    public function getOutstandingAttribute(): int
    {
        if (in_array($this->status, [self::CANCELLED, self::RECEIVED], true)) {
            return 0;
        }

        return (int) $this->items->sum(
            fn (PurchaseOrderItem $item) => max(0, $item->quantity - $item->quantity_received)
        );
    }

    /** Orders whose units are genuinely still coming. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    /**
     * A draft can still be changed; anything else cannot.
     *
     * Once an order is with a supplier, editing the lines here would leave the
     * shop's copy disagreeing with the one the supplier is picking from, which
     * is worse than not being able to edit it.
     */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public static function nextReference(): string
    {
        $today = now()->format('Ymd');
        $todays = static::where('reference', 'like', "PO-{$today}-%")->count();

        return sprintf('PO-%s-%03d', $today, $todays + 1);
    }
}
