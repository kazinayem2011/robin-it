<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'category',
        'excerpt',
        'summary',
        'content',
        'image_path',
        'link_url',
        'author_name',
        'author_role',
        'author',
        'read_time',
        'is_published',
        'published_at',
    ];

    /**
     * `excerpt` and `author_name` are what the editor and every blog view use;
     * `summary` and `author` are the original columns, kept in step so older rows
     * and any existing integrations keep working.
     */
    protected $appends = ['excerpt', 'author_name'];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function getExcerptAttribute(): ?string
    {
        return $this->attributes['excerpt'] ?? $this->attributes['summary'] ?? null;
    }

    public function setExcerptAttribute(?string $value): void
    {
        $this->attributes['excerpt'] = $value;
        $this->attributes['summary'] = $value;
    }

    public function getAuthorNameAttribute(): ?string
    {
        return $this->attributes['author_name'] ?? $this->attributes['author'] ?? null;
    }

    public function setAuthorNameAttribute(?string $value): void
    {
        $this->attributes['author_name'] = $value;
        $this->attributes['author'] = $value;
    }

    /**
     * Scope to only published articles.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
