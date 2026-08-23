<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
        'discount_type',
        'discount_value',
        'scope',
        'min_spend',
        'max_discount',
        'usage_limit',
        'per_user_limit',
        'used_count',
        'expires_at',
        'is_active',
    ];

    /** Whole cart, or only the products/categories the coupon is attached to. */
    public const SCOPE_ALL = 'all';

    public const SCOPE_PRODUCTS = 'products';

    public const SCOPE_CATEGORIES = 'categories';

    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_PRODUCTS, self::SCOPE_CATEGORIES];

    protected $casts = [
        'discount_value' => 'float',
        'min_spend' => 'float',
        'max_discount' => 'float',
        'usage_limit' => 'integer',
        'per_user_limit' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'coupon_product');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'coupon_category');
    }

    /** Codes are always stored and compared uppercase. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    /** Look a coupon up the same way everywhere: trimmed and case-insensitive. */
    public static function findByCode(?string $code): ?self
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : static::where('code', $code)->first();
    }

    /**
     * Whether this coupon covers a given product.
     *
     * A category-scoped coupon covers everything beneath the categories it names,
     * so a promo on "Components" also reaches "Graphics Cards" under it.
     */
    public function appliesTo(?Product $product): bool
    {
        if (! $product) {
            return false;
        }

        if ($this->scope === self::SCOPE_ALL) {
            return true;
        }

        if ($this->scope === self::SCOPE_PRODUCTS) {
            return $this->productIds()->contains($product->id);
        }

        return $this->categoryIds()->contains($product->category_id);
    }

    /** Product ids this coupon covers, resolved once per instance. */
    public function productIds(): Collection
    {
        return $this->memoised['product_ids'] ??= $this->products()->pluck('products.id');
    }

    /**
     * Category ids this coupon covers, expanded to include descendants so an
     * admin does not have to list every child of a section by hand.
     */
    public function categoryIds(): Collection
    {
        return $this->memoised['category_ids'] ??= collect(
            $this->categories()->pluck('categories.id')
                ->flatMap(fn ($id) => Category::getDescendantIds($id))
                ->unique()
                ->values()
        );
    }

    /**
     * The part of the cart this coupon is allowed to discount.
     *
     * Prices come from whichever level owns them, so a variant line is valued at
     * the option's price rather than the parent product's.
     */
    public function eligibleSubtotal(Cart $cart): float
    {
        $cart->loadMissing('items.product', 'items.variant');

        $total = 0.0;

        foreach ($cart->items as $item) {
            if ($this->appliesTo($item->product)) {
                $total += $item->unitPrice() * $item->quantity;
            }
        }

        return round($total, 2);
    }

    /**
     * Validate against a real cart, so a scoped coupon is judged on the lines it
     * actually covers rather than on the whole basket.
     *
     * min_spend is measured against the same amount the discount applies to:
     * for an unscoped coupon that is the whole subtotal, unchanged.
     */
    public function isValidForCart(Cart $cart, ?int $userId = null): array
    {
        $eligible = $this->eligibleSubtotal($cart);

        if ($this->scope !== self::SCOPE_ALL && $eligible <= 0) {
            return [
                'valid' => false,
                'message' => 'This code does not apply to anything in your cart. '.$this->scopeSummary(),
            ];
        }

        return $this->isValidForAmount($eligible, $userId);
    }

    /** A short line the storefront can show explaining what a code covers. */
    public function scopeSummary(): string
    {
        if ($this->scope === self::SCOPE_PRODUCTS) {
            $names = $this->products()->pluck('name');

            return $names->isEmpty()
                ? 'It is limited to selected products.'
                : 'It applies to: '.$names->take(4)->implode(', ').($names->count() > 4 ? ' and more.' : '.');
        }

        if ($this->scope === self::SCOPE_CATEGORIES) {
            $names = $this->categories()->pluck('name');

            return $names->isEmpty()
                ? 'It is limited to selected categories.'
                : 'It applies to '.$names->take(4)->implode(', ').($names->count() > 4 ? ' and more.' : '.');
        }

        return 'It applies to your whole order.';
    }

    /** Cheap per-instance memo so one request does not re-query the pivots. */
    protected array $memoised = [];

    /**
     * @param  int|null  $userId  when given, the per-customer cap is enforced too
     */
    public function isValidForAmount(float $subtotal, ?int $userId = null): array
    {
        if (! $this->is_active) {
            return ['valid' => false, 'message' => 'This coupon is no longer active.'];
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return ['valid' => false, 'message' => 'This coupon has expired.'];
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return ['valid' => false, 'message' => 'This coupon usage limit has been reached.'];
        }

        if ($userId && $this->per_user_limit !== null && $this->redemptionsBy($userId) >= $this->per_user_limit) {
            return [
                'valid' => false,
                'message' => $this->per_user_limit === 1
                    ? 'You have already used this coupon.'
                    : "You have already used this coupon {$this->per_user_limit} times.",
            ];
        }

        if ($subtotal < (float) $this->min_spend) {
            // For a scoped coupon the threshold is about the qualifying lines,
            // so say so rather than leaving the shopper to guess.
            $qualifier = $this->scope === self::SCOPE_ALL
                ? ' required to use this coupon.'
                : ' on qualifying items required to use this coupon.';

            return [
                'valid' => false,
                'message' => 'Minimum spend of ৳'.number_format($this->min_spend).$qualifier,
            ];
        }

        return [
            'valid' => true,
            'discount' => $this->discountFor($subtotal),
            'coupon' => $this,
        ];
    }

    /**
     * SSOT for the discount amount. Checkout recalculates with this rather than
     * trusting whatever the browser posted back.
     */
    public function discountFor(float $subtotal): float
    {
        if ($this->discount_type === 'percent') {
            $discount = ($subtotal * (float) $this->discount_value) / 100;

            if ($this->max_discount !== null && $discount > (float) $this->max_discount) {
                $discount = (float) $this->max_discount;
            }
        } else {
            $discount = (float) $this->discount_value;
        }

        // Never discount below zero-cost.
        return round(min($discount, $subtotal), 2);
    }

    /**
     * How many times one customer has already redeemed this code, counted from
     * the orders that carry it. Cancelled orders do not count against them.
     */
    public function redemptionsBy(int $userId): int
    {
        return Order::where('coupon_code', $this->code)
            ->where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    /**
     * Atomically record one redemption. Returns false when the limit was hit
     * concurrently, so the caller can back out rather than oversell the promo.
     */
    public function redeem(): bool
    {
        $query = static::whereKey($this->getKey());

        if ($this->usage_limit !== null) {
            $query->whereRaw('used_count < usage_limit');
        }

        $updated = $query->update(['used_count' => DB::raw('used_count + 1')]);

        if ($updated > 0) {
            $this->refresh();
        }

        return $updated > 0;
    }
}
