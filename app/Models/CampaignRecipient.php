<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person, on one channel, for one campaign.
 *
 * Written before anything is sent rather than logged after. That is what makes
 * a blast resumable: a campaign that stopped half way through can be picked up
 * without sending a second copy to everybody who already had it.
 */
class CampaignRecipient extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = [
        'campaign_id', 'name', 'contact', 'channel', 'status', 'error', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}
