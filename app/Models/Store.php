<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'branch_type',
        'city',
        'address',
        'phone',
        'email',
        'opening_hours',
        'map_embed_url',
        'sort_order',
        'is_active',
        'holds_stock',
        'fulfils_online',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        // id as a tie-break so branches sharing a position keep a stable order
        // rather than shuffling between requests.
        return $query->where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');
    }

    public function stockLevels()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Branches that actually keep inventory. */
    public function scopeHoldsStock($query)
    {
        return $query->where('holds_stock', true)->where('is_active', true);
    }

    /**
     * The branch online orders are picked from.
     *
     * Falls back to the first stock-holding branch so a misconfigured shop
     * still takes orders rather than rejecting every checkout.
     */
    public static function onlineFulfilment(): ?self
    {
        return static::where('fulfils_online', true)->where('is_active', true)->first()
            ?? static::holdsStock()->orderBy('sort_order')->orderBy('id')->first();
    }
}
