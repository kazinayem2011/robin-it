<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One answer a product may give: "Wi-Fi 6", "Dual Band", "751 Mbps to 1200 Mbps".
 *
 * A band carries its bounds so the label stays a thing a shopper ticks while
 * the numbers remain available for sorting and for placing a new product.
 */
class AttributeValue extends Model
{
    protected $fillable = ['attribute_id', 'label', 'slug', 'range_from', 'range_to', 'sort_order'];

    protected function casts(): array
    {
        return [
            'range_from' => 'float',
            'range_to' => 'float',
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'attribute_value_product');
    }

    /**
     * Whether a measured number belongs in this band.
     *
     * Open at either end on purpose: "Up to 300 Mbps" has no floor and
     * "1801 Mbps and Above" no ceiling, and both are ordinary rows on a
     * shop's filter list.
     */
    public function covers(float $number): bool
    {
        if ($this->range_from === null && $this->range_to === null) {
            return false;
        }

        return ($this->range_from === null || $number >= $this->range_from)
            && ($this->range_to === null || $number <= $this->range_to);
    }
}
