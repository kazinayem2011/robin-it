<?php

namespace App\Models;

use App\Support\RichText;
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

    /**
     * The article body, stored cleaned.
     *
     * Rendered as raw HTML on the article page. The admin controller cleaned
     * it on the way through and nothing else did, so the rule held only for as
     * long as every future write went through that one method. Here it cannot
     * be gone around.
     */
    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = $value === null ? null : RichText::clean($value);
    }

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
