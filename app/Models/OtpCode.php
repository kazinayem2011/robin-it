<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time code sent to a mobile number.
 *
 * Rows are kept after use rather than deleted: a customer arguing that they
 * never asked for a code, or a burst of requests worth understanding, both need
 * the history. OtpService prunes what is old enough to be useless.
 */
class OtpCode extends Model
{
    /** Confirming a mobile number at sign-up. */
    public const PURPOSE_REGISTER = 'register';

    /** Getting back into an account when the password is gone. */
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const PURPOSES = [
        self::PURPOSE_REGISTER,
        self::PURPOSE_PASSWORD_RESET,
    ];

    protected $fillable = [
        'phone', 'purpose', 'code_hash', 'expires_at', 'attempts', 'used_at', 'ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * Never sent anywhere. Nothing outside the service has any business
     * reading the hash, and a stray toArray() in a controller is how it
     * would leave.
     */
    protected $hidden = ['code_hash'];

    /** A code that can still be used. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function scopeFor(Builder $query, string $phone, string $purpose): Builder
    {
        return $query->where('phone', $phone)->where('purpose', $purpose);
    }
}
