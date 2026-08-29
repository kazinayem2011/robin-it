<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message sent to everybody at once.
 *
 * See the migration. In short: the shop had been collecting a mailing list with
 * nowhere to send it.
 */
class Campaign extends Model
{
    public const EMAIL = 'email';

    public const SMS = 'sms';

    public const BOTH = 'both';

    public const CHANNELS = [
        self::EMAIL => 'Email',
        self::SMS => 'Text message',
        self::BOTH => 'Both',
    ];

    public const AUDIENCES = [
        'subscribers' => 'Mailing list',
        'customers' => 'Customers with an account',
        'all' => 'Everyone',
    ];

    public const DRAFT = 'draft';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::SENDING => 'Sending',
        self::SENT => 'Sent',
        self::FAILED => 'Stopped',
    ];

    protected $fillable = [
        'title', 'subject', 'body', 'channel', 'audience', 'status',
        'recipient_count', 'sent_count', 'failed_count', 'sms_parts',
        'started_at', 'finished_at', 'user_id', 'created_by_name',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'recipient_count' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'sms_parts' => 'integer',
    ];

    protected $appends = ['status_label', 'channel_label', 'audience_label'];

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function getChannelLabelAttribute(): string
    {
        return self::CHANNELS[$this->channel] ?? ucfirst((string) $this->channel);
    }

    public function getAudienceLabelAttribute(): string
    {
        return self::AUDIENCES[$this->audience] ?? ucfirst((string) $this->audience);
    }

    /**
     * A campaign can be rewritten only before anybody has had it.
     *
     * Editing one that has gone out would leave the record disagreeing with
     * what landed on several thousand phones, which is the one copy of it that
     * cannot be corrected.
     */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function sendsEmail(): bool
    {
        return in_array($this->channel, [self::EMAIL, self::BOTH], true);
    }

    public function sendsSms(): bool
    {
        return in_array($this->channel, [self::SMS, self::BOTH], true);
    }
}
