<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Readable, collision-free slugs.
 *
 * Lived as a private helper on the old god controller, where products and blog
 * posts were the only callers that could reach it. Categories grew their own
 * near-identical loop as a result; both now come through here.
 */
class SlugFactory
{
    /**
     * A slug for `$source` that nothing of `$modelClass` is already using.
     *
     * @param  class-string<Model>  $modelClass
     * @param  int|null  $ignoreId  row to disregard, so re-saving a record does
     *                              not collide with the slug it already owns
     */
    public static function unique(string $modelClass, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while (self::taken($modelClass, $slug, $ignoreId)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private static function taken(string $modelClass, string $slug, ?int $ignoreId): bool
    {
        $query = $modelClass::where('slug', $slug);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}
