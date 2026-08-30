<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductQuestion extends Model
{
    protected $fillable = [
        'product_id', 'user_id', 'name', 'email',
        'question', 'answer', 'answered_by', 'answered_at', 'is_published',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'is_published' => 'boolean',
    ];

    protected $hidden = ['email'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function asker()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function answerer()
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /**
     * What a shopper may see: published, newest first.
     *
     * Unanswered questions are included when published, deliberately — "asked
     * three days ago, no answer yet" is information a shopper can act on, and
     * hiding it only makes the shop look like it has never been asked anything.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_published', true)->latest();
    }

    public function isAnswered(): bool
    {
        return filled($this->answer);
    }
}
