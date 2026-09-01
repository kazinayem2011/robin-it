<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question a shelf asks about a product: Wi-Fi Standard, Panel Type, RAM.
 *
 * The answers live in AttributeValue and are a curated list, which is the
 * whole point — a facet needs values two products can share exactly, and the
 * free-text spec sheet cannot give that.
 */
class Attribute extends Model
{
    /** One answer from the list: Wi-Fi 6, Dual Band, IPS. */
    public const ENUM = 'enum';

    /** A number, offered as named bands: "301 Mbps to 750 Mbps". */
    public const NUMBER = 'number';

    /** Many answers at once: USB Port, Parental Controls, Mesh Support. */
    public const FLAGS = 'flags';

    public const INPUT_TYPES = [self::ENUM, self::NUMBER, self::FLAGS];

    protected $fillable = ['name', 'slug', 'unit', 'input_type', 'sort_order'];

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'attribute_category')
            ->withPivot('sort_order');
    }

    /** Which band a measured number falls into, or null when none covers it. */
    public function bandFor(float $number): ?AttributeValue
    {
        return $this->values
            ->first(fn (AttributeValue $v) => $v->covers($number));
    }
}
