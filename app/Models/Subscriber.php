<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Subscriber extends Model
{
    public const SUBSCRIBED = 'subscribed';

    public const UNSUBSCRIBED = 'unsubscribed';

    protected $fillable = [
        'email', 'name', 'status', 'token', 'source', 'subscribed_at', 'unsubscribed_at',
    ];

    /** The token is how someone leaves; it has no business in a JSON payload. */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function isSubscribed(): bool
    {
        return $this->status === self::SUBSCRIBED;
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    public function unsubscribeUrl(): string
    {
        return url('/unsubscribe/'.$this->token);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::SUBSCRIBED);
    }
}
