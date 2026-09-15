<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The words in one of the shop's emails.
 *
 * The layout is not here — `emails.layouts.master` holds the table markup and
 * inline styles that Outlook needs, and this holds what is said inside it.
 */
class EmailTemplate extends Model
{
    protected $fillable = ['key', 'name', 'group', 'subject', 'body', 'hint', 'variables', 'is_active'];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
