<?php

namespace App\Support;

use App\Models\Category;
use App\Models\PcBuilderSlot;
use Illuminate\Support\Facades\DB;

/**
 * The PC Builder's parts as a file that travels with the code.
 *
 * Parts are set up in one shop's admin (usually locally) and have to arrive
 * the same on another. Category ids differ between databases, so a part's
 * shelves are written by slug and found again by slug on the other side.
 */
class PcBuilderPartsFile
{
    public static function path(): string
    {
        return database_path('data/pc-builder-parts.json');
    }

    /**
     * Every part, hidden ones too, as plain data.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function export(): array
    {
        return PcBuilderSlot::with('categories')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PcBuilderSlot $slot) => [
                'key' => $slot->key,
                'name' => $slot->name,
                'icon' => $slot->icon,
                'group' => $slot->group,
                'is_required' => $slot->is_required,
                'hint' => $slot->hint,
                'max_quantity' => $slot->max_quantity,
                'is_active' => $slot->is_active,
                'default_slugs' => $slot->default_slugs,
                'compat_role' => $slot->compat_role,
                'categories' => $slot->categories->pluck('slug')->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Make this database's parts match the list.
     *
     * A part is matched to one already here by its fit-check role, then its
     * key, then its old shelf names, so a saved build keeps finding it. Parts
     * here that the list does not have are removed, unless the fit check needs
     * them. Shelves the list names but this shop does not have are skipped;
     * the names that are missing are returned.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array{parts: int, missing_categories: array<int, string>}
     */
    public static function import(array $parts): array
    {
        $missing = [];

        DB::transaction(function () use ($parts, &$missing) {
            $existing = PcBuilderSlot::all();
            $kept = [];

            foreach (array_values($parts) as $i => $part) {
                $slot = $existing->first(fn ($s) => ! in_array($s->id, $kept, true) && (
                    ($part['compat_role'] ?? null) && $s->compat_role === $part['compat_role']
                    || ($part['key'] ?? null) && $s->key === $part['key']
                    || ($part['default_slugs'] ?? null) && $s->default_slugs === $part['default_slugs']
                )) ?? new PcBuilderSlot;

                $slot->fill([
                    'key' => $part['key'] ?? null,
                    'name' => $part['name'] ?? null,
                    'icon' => $part['icon'] ?? 'Package',
                    'group' => $part['group'] ?? PcBuilderSlot::GROUP_EXTRAS,
                    'is_required' => (bool) ($part['is_required'] ?? false),
                    'hint' => $part['hint'] ?? null,
                    'max_quantity' => max(1, min(10, (int) ($part['max_quantity'] ?? 1))),
                    'sort_order' => $i + 1,
                    'is_active' => (bool) ($part['is_active'] ?? true),
                    'default_slugs' => $part['default_slugs'] ?? null,
                    'compat_role' => $part['compat_role'] ?? null,
                ])->save();

                $slugs = $part['categories'] ?? [];
                $found = Category::whereIn('slug', $slugs)->pluck('id', 'slug');
                $missing = array_merge($missing, array_values(array_diff($slugs, $found->keys()->all())));

                $slot->categories()->sync(
                    collect($slugs)->filter(fn ($slug) => $found->has($slug))->values()
                        ->mapWithKeys(fn ($slug, $position) => [$found[$slug] => ['position' => $position]])
                        ->all()
                );

                $kept[] = $slot->id;
            }

            // Gone from the list; the fit check's own parts always stay.
            PcBuilderSlot::whereNotIn('id', $kept)->whereNull('compat_role')->delete();
        });

        app(PcBuilderSlots::class)->flush();

        return ['parts' => count($parts), 'missing_categories' => array_values(array_unique($missing))];
    }
}
