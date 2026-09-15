<?php

namespace App\Models;

use App\Services\SmsService;
use Illuminate\Database\Eloquent\Model;

/**
 * The words in one of the shop's text messages.
 *
 * Bengali, because the gateway requires it, and counted because it charges by
 * the part: 160 characters of plain ASCII, but only 70 once a single Bengali
 * letter appears. Three words added here can double what every order costs.
 */
class SmsTemplate extends Model
{
    protected $fillable = ['key', 'name', 'group', 'body', 'hint', 'variables', 'is_active'];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** What this costs to send, as the gateway counts it. */
    public function getPartsAttribute(): int
    {
        return SmsService::parts((string) $this->body);
    }
}
