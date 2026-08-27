<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a customer wrote in, and what was done about it.
 */
class ContactMessage extends Model
{
    /** New, being dealt with, done. */
    public const STATUS_NEW = 'new';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_NEW, self::STATUS_OPEN, self::STATUS_CLOSED];

    protected $fillable = [
        'name', 'email', 'phone', 'subject', 'message',
        'status', 'assigned_to', 'closed_at', 'closed_by', 'ip_address',
    ];

    protected $appends = ['status_label', 'is_closed'];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ContactReply::class)->oldest();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function getIsClosedAttribute(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_NEW => 'New',
            self::STATUS_OPEN => 'In progress',
            self::STATUS_CLOSED => 'Closed',
            default => ucfirst((string) $this->status),
        };
    }

    /**
     * New first, then in progress, then closed; newest within each.
     *
     * A CASE rather than MySQL's FIELD(), because the tests run on SQLite too
     * and FIELD() is not a function there.
     */
    public function scopeInbox($query)
    {
        return $query
            ->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'open' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at');
    }
}
