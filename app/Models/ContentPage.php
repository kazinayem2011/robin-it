<?php

namespace App\Models;

use App\Support\RichText;
use Illuminate\Database\Eloquent\Model;

/**
 * A page whose words belong to the shop rather than to the codebase.
 */
class ContentPage extends Model
{
    /** The ones the footer links to; these may be edited but not deleted. */
    public const SYSTEM_SLUGS = ['about', 'contact', 'privacy', 'terms', 'return-policy'];

    protected $fillable = [
        'slug', 'title', 'subtitle', 'body',
        'meta_title', 'meta_description', 'is_published', 'is_system', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Store the body cleaned.
     *
     * Purified on the way in rather than on the way out, so what is in the
     * column is what is safe to render — nothing downstream has to remember to
     * clean it, and a page rendered from an old cache cannot carry a script
     * that was pasted in before the rule existed.
     */
    public function setBodyAttribute(?string $value): void
    {
        $this->attributes['body'] = $value === null ? null : RichText::clean($value);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
