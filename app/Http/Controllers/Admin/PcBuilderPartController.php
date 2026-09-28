<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\PcBuilderSlot;
use App\Support\PcBuilderSlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Adding, changing and ordering the PC Builder's parts.
 *
 * The parts were an array in the code, so a shop that sells Anti Virus had no
 * way to offer it in the builder. Seven parts carry the compatibility check —
 * Processor, Motherboard, RAM, Cooler, Graphics Card, Power Supply, Casing —
 * and those can be changed or hidden but not deleted.
 */
class PcBuilderPartController extends Controller
{
    public function __construct(private readonly PcBuilderSlots $parts) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $slot = DB::transaction(function () use ($data) {
            $slot = PcBuilderSlot::create([
                'key' => $this->freshKey($data['name']),
                'name' => $data['name'],
                'icon' => $data['icon'],
                'group' => $data['group'],
                'is_required' => $data['is_required'],
                'hint' => $data['hint'] ?? null,
                'max_quantity' => $data['max_quantity'],
                'is_active' => $data['is_active'],
                'sort_order' => (int) PcBuilderSlot::max('sort_order') + 1,
            ]);
            $this->syncCategories($slot, $data['category_ids']);

            return $slot;
        });

        $this->parts->flush();

        return $this->successResponse($this->row($slot->fresh()), "{$slot->name} added to the PC Builder.", 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $slot = PcBuilderSlot::findOrFail($id);
        $data = $this->validated($request);

        DB::transaction(function () use ($slot, $data) {
            /*
             * Saved builds and the compatibility check know a built-in part by
             * the shelf it answered to. Pointing it at other shelves would
             * change that name, so the one it has now is written down and
             * kept.
             */
            if ($slot->key === null) {
                $slot->key = $this->parts->find($this->currentId($slot))['id'] ?? $this->freshKey($data['name']);
            }

            $slot->fill([
                'name' => $data['name'],
                'icon' => $data['icon'],
                'group' => $data['group'],
                'is_required' => $data['is_required'],
                'hint' => $data['hint'] ?? null,
                'max_quantity' => $data['max_quantity'],
                'is_active' => $data['is_active'],
            ])->save();

            $this->syncCategories($slot, $data['category_ids']);
        });

        $this->parts->flush();

        return $this->successResponse($this->row($slot->fresh()), "{$slot->name} saved.");
    }

    public function destroy(int $id): JsonResponse
    {
        $slot = PcBuilderSlot::findOrFail($id);

        if ($slot->checksCompatibility()) {
            return $this->errorResponse(
                'The builder checks this part fits the others, so it cannot be deleted. Hide it instead.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $name = $this->displayName($slot);
        $slot->delete();
        $this->parts->flush();

        return $this->successResponse([], "{$name} removed from the PC Builder.");
    }

    /** One step up or down the list. */
    public function move(Request $request, int $id): JsonResponse
    {
        $direction = $request->validate(['direction' => 'required|in:up,down'])['direction'];

        DB::transaction(function () use ($id, $direction) {
            // Renumbered first, so two parts sharing a number still swap.
            PcBuilderSlot::orderBy('sort_order')->orderBy('id')->get()
                ->each(fn ($s, $i) => $s->sort_order === $i + 1 ? null : $s->update(['sort_order' => $i + 1]));

            $list = PcBuilderSlot::orderBy('sort_order')->get()->values();
            $at = $list->search(fn ($s) => $s->id === $id);
            $to = $direction === 'up' ? $at - 1 : $at + 1;

            if ($at === false || ! isset($list[$to])) {
                return;
            }

            [$a, $b] = [$list[$at], $list[$to]];
            [$aOrder, $bOrder] = [$a->sort_order, $b->sort_order];
            $a->update(['sort_order' => $bOrder]);
            $b->update(['sort_order' => $aOrder]);
        });

        $this->parts->flush();

        return $this->successResponse([], 'Order saved.');
    }

    /**
     * The shape the admin table reads.
     *
     * @return array<string, mixed>
     */
    public function row(PcBuilderSlot $slot): array
    {
        $resolved = $this->parts->all(true)->first(fn ($r) => $r['slot']->id === $slot->id);
        $categories = $resolved['categories'] ?? collect();

        return [
            'id' => $slot->id,
            // What the builder and the health figures call this part.
            'builder_id' => $resolved['id'] ?? null,
            'name' => $resolved['name'] ?? $this->displayName($slot),
            'icon' => $slot->icon,
            'group' => $slot->group,
            'is_required' => $slot->is_required,
            'hint' => $slot->hint,
            'max_quantity' => $slot->max_quantity,
            'is_active' => $slot->is_active,
            'checks_compatibility' => $slot->checksCompatibility(),
            'categories' => $categories->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'path' => $this->ancestry($c),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'integer|exists:categories,id',
            'icon' => ['required', Rule::in(PcBuilderSlot::ICONS)],
            'group' => ['required', Rule::in([PcBuilderSlot::GROUP_CORE, PcBuilderSlot::GROUP_EXTRAS])],
            'is_required' => 'boolean',
            'hint' => 'nullable|string|max:160',
            'max_quantity' => 'required|integer|min:1|max:10',
            'is_active' => 'boolean',
        ], [
            'category_ids.required' => 'Choose at least one category for its products.',
            'category_ids.min' => 'Choose at least one category for its products.',
        ]);

        $data['is_required'] = (bool) ($data['is_required'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    private function syncCategories(PcBuilderSlot $slot, array $ids): void
    {
        $slot->categories()->sync(
            collect(array_values(array_unique($ids)))
                ->mapWithKeys(fn ($id, $position) => [(int) $id => ['position' => $position]])
                ->all()
        );
    }

    /** A name for a new part that nothing else answers to. */
    private function freshKey(string $name): string
    {
        $base = Str::slug($name) ?: 'part';
        $taken = $this->parts->all(true)->pluck('id')
            ->merge(PcBuilderSlot::whereNotNull('key')->pluck('key'))
            ->all();
        $key = $base;

        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = "{$base}-{$n}";
        }

        return $key;
    }

    private function currentId(PcBuilderSlot $slot): string
    {
        return $this->parts->all(true)->first(fn ($r) => $r['slot']->id === $slot->id)['id'] ?? '';
    }

    private function displayName(PcBuilderSlot $slot): string
    {
        return $slot->name ?: ($this->parts->all(true)->first(fn ($r) => $r['slot']->id === $slot->id)['name'] ?? 'Part');
    }

    private function ancestry(Category $category): string
    {
        $names = [];
        $parent = $category->parent;

        for ($guard = 0; $parent && $guard < 6; $guard++) {
            array_unshift($names, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' › ', $names);
    }
}
