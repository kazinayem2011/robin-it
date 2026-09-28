<?php

namespace App\Support;

use App\Models\Category;
use App\Models\PcBuilderSlot;
use Illuminate\Support\Collection;

/**
 * The PC Builder's parts, each resolved to the categories it draws from.
 *
 * A part answers to its `key` once it has one; a built-in part that nobody has
 * re-pointed answers to the first of its old shelf names that exists, exactly
 * as the list in the code did, so saved builds and the compatibility check
 * find it where they always did.
 *
 * Read once per request.
 */
class PcBuilderSlots
{
    /** @var Collection<int, array{slot: PcBuilderSlot, id: string, name: string, categories: Collection<int, Category>}>|null */
    private ?Collection $resolved = null;

    /**
     * Every part, in order. Hidden ones only when asked for.
     *
     * @return Collection<int, array{slot: PcBuilderSlot, id: string, name: string, categories: Collection<int, Category>}>
     */
    public function all(bool $withHidden = false): Collection
    {
        $this->resolved ??= $this->resolve();

        return $withHidden
            ? $this->resolved
            : $this->resolved->filter(fn ($r) => $r['slot']->is_active)->values();
    }

    /** The part a builder id names, shown or hidden. */
    public function find(string $id): ?array
    {
        return $this->all(true)->first(fn ($r) => $r['id'] === $id);
    }

    public function flush(): void
    {
        $this->resolved = null;
    }

    private function resolve(): Collection
    {
        $slots = PcBuilderSlot::with(['categories' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $fallbacks = Category::where('is_active', true)
            ->whereIn('slug', $slots->pluck('default_slugs')->filter()->flatten()->unique()->values())
            ->get()
            ->keyBy('slug');

        return $slots->map(function (PcBuilderSlot $slot) use ($fallbacks) {
            $categories = $slot->categories;

            if ($categories->isEmpty()) {
                $first = collect($slot->default_slugs ?? [])
                    ->map(fn ($slug) => $fallbacks->get($slug))
                    ->first(fn ($category) => $category !== null);
                $categories = collect($first ? [$first] : []);
            }

            return [
                'slot' => $slot,
                'id' => $slot->key
                    ?? $categories->first()?->slug
                    ?? ($slot->default_slugs[0] ?? 'part-'.$slot->id),
                'name' => $slot->name ?: ($categories->first()?->name ?? 'Part'),
                'categories' => $categories->values(),
            ];
        })->values();
    }
}
