<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id', 'brand_id', 'name', 'slug', 'price',
        'discount_price', 'stock_quantity', 'short_description',
        'description', 'is_featured', 'is_active',
        'has_variants', 'variant_attributes',
    ];

    /**
     * Stock is never assigned directly — it is the cached balance of the stock
     * ledger and only App\Services\StockService may move it. It stays fillable
     * for the initial create, where the ledger's opening row is written alongside.
     */

    /**
     * Computed pricing/availability the frontend can trust, present on every
     * serialized product so the UI never has to re-derive "is this discounted?".
     */
    protected $appends = ['effective_price', 'has_discount', 'in_stock'];

    /**
     * Without these casts the MySQL driver hands decimals back as strings, so
     * a discount_price of "0.00" reads as truthy and a product renders as 100% off.
     */
    protected $casts = [
        'price' => 'float',
        'discount_price' => 'float',
        'stock_quantity' => 'integer',
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'has_variants' => 'boolean',
        'variant_attributes' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    public function activeVariants()
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true)
            ->orderBy('position')->orderBy('id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function specifications()
    {
        return $this->hasMany(ProductSpecification::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Eager-load the real rating, review count and units sold in one pass.
     *
     * Product cards used to ship hardcoded "4.9 / 25 reviews / 18 sold" for every
     * item; these aggregates replace that with the truth, without an N+1.
     */
    public function scopeWithCatalogAggregates($query)
    {
        return $query
            ->withCount(['reviews as approved_reviews_count' => fn ($q) => $q->where('is_approved', true)])
            ->withAvg(['reviews as approved_rating_avg' => fn ($q) => $q->where('is_approved', true)], 'rating')
            ->withSum(
                ['orderItems as sold_count' => fn ($q) => $q->whereHas(
                    'order',
                    fn ($o) => $o->where('status', '!=', 'cancelled')
                )],
                'quantity'
            );
    }

    /**
     * SSOT: is this product genuinely discounted right now?
     *
     * A discount only counts when it is set, above zero, and below the list price.
     * Every price display, cart total and order line must agree on this.
     */
    public function hasDiscount(): bool
    {
        return $this->discount_price !== null
            && (float) $this->discount_price > 0
            && (float) $this->discount_price < (float) $this->price;
    }

    /**
     * SSOT: the price the customer actually pays for one unit.
     */
    public function getEffectivePriceAttribute(): float
    {
        return $this->hasDiscount()
            ? (float) $this->discount_price
            : (float) $this->price;
    }

    /**
     * Amount saved per unit, or 0 when there is no discount.
     */
    public function getSavingAttribute(): float
    {
        return $this->hasDiscount()
            ? (float) $this->price - (float) $this->discount_price
            : 0.0;
    }

    /**
     * Whether the requested quantity can be fulfilled from stock right now.
     *
     * For a variant product this asks about the whole product; a specific option
     * is answered by ProductVariant::canFulfil, because one option being in stock
     * says nothing about the one the shopper picked.
     */
    public function canFulfil(int $quantity): bool
    {
        return $this->is_active && $this->stock_quantity >= $quantity;
    }

    public function isInStock(): bool
    {
        return $this->is_active && $this->stock_quantity > 0;
    }

    /** The lowest effective price across the options, for "from ৳X" on a card. */
    public function getPriceFromAttribute(): float
    {
        if (! $this->has_variants) {
            return $this->effective_price;
        }

        $variants = $this->relationLoaded('activeVariants')
            ? $this->activeVariants
            : ($this->relationLoaded('variants') ? $this->variants->where('is_active', true) : null);

        if ($variants === null || $variants->isEmpty()) {
            return $this->effective_price;
        }

        return (float) $variants->min(fn ($v) => $v->effective_price);
    }

    /**
     * Look up a specification value by name.
     *
     * Matching is case-insensitive and partial, because the catalogue uses
     * variations like "Socket", "CPU Socket" and "Socket Type".
     *
     * @param  string|array<int, string>  $names  first match wins
     */
    public function spec(string|array $names): ?string
    {
        if (! $this->relationLoaded('specifications')) {
            $this->load('specifications');
        }

        foreach ((array) $names as $needle) {
            $match = $this->specifications->first(
                fn ($s) => stripos((string) $s->name, $needle) !== false
            );

            if ($match && trim((string) $match->value) !== '') {
                return trim((string) $match->value);
            }
        }

        return null;
    }

    /**
     * Power draw in watts, read from the product's TDP / Power / Wattage spec.
     *
     * The spec columns are `name` and `value`; both the API and the PC Builder UI
     * were reading `spec_name`/`spec_value`, so every component silently fell back
     * to a default and the PSU recommendation was guesswork.
     */
    public function estimatedWattage(?int $default = null): ?int
    {
        if (! $this->relationLoaded('specifications')) {
            return $default;
        }

        $spec = $this->specifications->first(
            fn ($s) => stripos((string) $s->name, 'tdp') !== false
                || stripos((string) $s->name, 'power') !== false
                || stripos((string) $s->name, 'wattage') !== false
        );

        if ($spec && preg_match('/(\d+)\s*w/i', (string) $spec->value, $m)) {
            return (int) $m[1];
        }

        return $default;
    }

    public function getHasDiscountAttribute(): bool
    {
        return $this->hasDiscount();
    }

    public function getInStockAttribute(): bool
    {
        return $this->isInStock();
    }

    /**
     * Only count reviews that are actually published. No invented fallbacks —
     * an unreviewed product reports zero so the UI can say "no reviews yet".
     */
    public function getAverageRatingAttribute(): float
    {
        if ($this->relationLoaded('reviews')) {
            $approved = $this->reviews->where('is_approved', true);

            return $approved->isEmpty() ? 0.0 : round((float) $approved->avg('rating'), 1);
        }

        return round((float) $this->reviews()->where('is_approved', true)->avg('rating'), 1);
    }

    public function getReviewCountAttribute(): int
    {
        if ($this->relationLoaded('reviews')) {
            return $this->reviews->where('is_approved', true)->count();
        }

        return (int) $this->reviews()->where('is_approved', true)->count();
    }

    /** Active products only — used by every storefront query. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Products carrying a real, in-force discount. */
    public function scopeDiscounted($query)
    {
        return $query->whereNotNull('discount_price')
            ->where('discount_price', '>', 0)
            ->whereColumn('discount_price', '<', 'price');
    }
}
