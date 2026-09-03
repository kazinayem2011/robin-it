<?php

namespace App\Models;

use App\Support\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A campaign the shop is running.
 *
 * Not a discount — those live on products and are worked out from prices.
 * This is the thing a shop announces and puts a poster up for: it has a name,
 * a window, the outlets it applies at, and a page explaining the terms.
 *
 * @see database/migrations/2026_09_21_100000_create_the_offers_the_shop_runs.php
 */
class Offer extends Model
{
    use HasFactory;

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_RUNNING = 'running';

    public const STATUS_ENDED = 'ended';

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'image_path',
        'starts_at',
        'ends_at',
        'availability',
        'link_url',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Worked out here rather than in each of the three places that ask, and
     * sent with every offer so the storefront never has to compare dates in
     * the browser's timezone to find out what is on.
     */
    protected $appends = ['status'];

    /**
     * The terms, stored cleaned.
     *
     * Rendered as raw HTML on the offer's page, so the cleaning cannot live in
     * the controller that happens to write it today — see BlogPost, where the
     * same reasoning applies.
     */
    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = $value === null ? null : RichText::clean($value);
    }

    public function getStatusAttribute(): string
    {
        $now = now();

        if ($this->starts_at && $this->starts_at->isAfter($now)) {
            return self::STATUS_UPCOMING;
        }

        if ($this->ends_at && $this->ends_at->isBefore($now)) {
            return self::STATUS_ENDED;
        }

        return self::STATUS_RUNNING;
    }

    /** Switched on by staff. Says nothing about whether it is running today. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** On today: started, and not finished. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->active()
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** Announced, not yet begun. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->active()->where('starts_at', '>', now());
    }

    /**
     * What the offers page shows: what is on, and what is coming.
     *
     * An offer that has finished drops off the list but keeps its page, so a
     * link already sent to a customer still explains what it was rather than
     * turning into a 404.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->active()
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')
            // Ending soonest first: that is the one worth reading today.
            ->orderByRaw('ends_at is null, ends_at asc')
            ->orderByDesc('id');
    }
}
