<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address a category used to answer at.
 *
 * Written when a slug changes, read when one is asked for and not found, so a
 * rename redirects rather than 404s.
 */
class CategorySlugHistory extends Model
{
    protected $table = 'category_slug_history';

    public const UPDATED_AT = null;

    protected $fillable = ['category_id', 'slug', 'created_at'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
