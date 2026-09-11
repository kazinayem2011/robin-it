<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttributeRequest;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Support\SearchTerm;
use App\Support\SlugFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The questions the filter sidebar asks, and the answers it offers.
 *
 * There was no screen for this at all. The 66 attributes existed because a
 * seeder made them, and nothing in the application could add a sixty-seventh —
 * so a new category could be given products, photos and a spec sheet, and still
 * be unfilterable, with no way to fix it short of a developer and a migration.
 *
 * These are deliberately not the spec sheet. A specification is prose written
 * for someone reading one product; a filter value is a controlled answer two
 * products can share exactly, which is the only thing a checkbox can be built
 * from.
 */
class AttributeController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $attributes = Attribute::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', SearchTerm::contains($search)))
            ->with([
                'values' => fn ($q) => $q->withCount('products'),
                'categories:id,name,slug,parent_id',
                'categories.parent:id,name,parent_id',
                'categories.parent.parent:id,name',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Attribute $a) => $this->present($a))
            ->values()
            ->all();

        return Inertia::render('Admin/Attributes', [
            'attributes' => $attributes,
            'filters' => ['search' => $search],
            'counts' => [
                'total' => Attribute::count(),
                'values' => AttributeValue::count(),
                /*
                 * The number worth watching. A filter attached to no shelf is
                 * one no shopper will ever be offered, however complete its
                 * list of answers looks on this screen.
                 */
                'unattached' => Attribute::doesntHave('categories')->count(),
            ],
        ]);
    }

    public function store(AttributeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $attribute = DB::transaction(function () use ($validated) {
            $attribute = Attribute::create([
                'name' => $validated['name'],
                'slug' => SlugFactory::unique(Attribute::class, $validated['name']),
                'unit' => $this->unitFor($validated),
                'input_type' => $validated['input_type'],
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            $this->syncValues($attribute, $validated['values']);
            $attribute->categories()->sync($validated['category_ids'] ?? []);

            return $attribute;
        });

        return $this->successResponse(
            $this->present($this->reload($attribute)),
            "Filter '{$attribute->name}' created.",
            201
        );
    }

    public function update(AttributeRequest $request, int $id): JsonResponse
    {
        $attribute = Attribute::findOrFail($id);
        $validated = $request->validated();

        DB::transaction(function () use ($attribute, $validated) {
            $attribute->update([
                'name' => $validated['name'],
                'unit' => $this->unitFor($validated),
                'input_type' => $validated['input_type'],
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            $this->syncValues($attribute, $validated['values']);
            $attribute->categories()->sync($validated['category_ids'] ?? []);
        });

        return $this->successResponse(
            $this->present($this->reload($attribute)),
            "Filter '{$attribute->name}' updated."
        );
    }

    /**
     * Ask the same question on another shelf.
     *
     * Twenty-nine of the sixty-six filters in this shop are already a repeat
     * of another by name — Features is asked by six shelves, Type by four,
     * Interface by four — so re-creating a question for a different shelf is
     * not an edge case, it is nearly half of them. Processor Model carries
     * sixteen answers, and retyping those to ask the same thing about desktops
     * is the work this removes.
     *
     * The shelves are deliberately not copied. They are the one thing that
     * differs between the original and the copy, and an unattached filter is
     * marked as such on the list — so what arrives is visibly the thing still
     * needing a decision, rather than a second filter quietly answering for
     * the same shelf as the first.
     *
     * The name is not suffixed either, for the same reason it is not unique:
     * asking "Display Type" about monitors as well as laptops is the intended
     * shape, and a copy called "Display Type (Copy)" would have to be renamed
     * back every single time. The slug takes the suffix instead, where nobody
     * has to read it.
     */
    public function duplicate(int $id): JsonResponse
    {
        $source = Attribute::with('values')->findOrFail($id);

        $copy = DB::transaction(function () use ($source) {
            $copy = Attribute::create([
                'name' => $source->name,
                'slug' => SlugFactory::unique(Attribute::class, $source->name),
                'unit' => $source->unit,
                'input_type' => $source->input_type,
                'sort_order' => $source->sort_order,
            ]);

            foreach ($source->values as $value) {
                $copy->values()->create([
                    'label' => $value->label,
                    'slug' => SlugFactory::uniqueWithin(
                        AttributeValue::class,
                        $value->label,
                        ['attribute_id' => $copy->id]
                    ),
                    'range_from' => $value->range_from,
                    'range_to' => $value->range_to,
                    'sort_order' => $value->sort_order,
                ]);
            }

            return $copy;
        });

        return $this->successResponse(
            $this->present($this->reload($copy)),
            "Copied '{$copy->name}' with {$source->values->count()} answer(s). Choose the shelves it asks about.",
            201
        );
    }

    /**
     * Deleting takes the answers with it, and with them every product's tick.
     *
     * The foreign key cascades, so this would quietly un-tag products rather
     * than fail — which is why it is refused while anything is using it. The
     * way to retire a filter that is in use is to unlink it from its shelves:
     * the sidebar stops offering it and the tags survive in case it comes back.
     */
    public function destroy(int $id): JsonResponse
    {
        $attribute = Attribute::with('values')->findOrFail($id);
        $tagged = $this->taggedCount($attribute);

        if ($tagged > 0) {
            throw new StorefrontException(
                "'{$attribute->name}' is in use on {$tagged} product(s), and deleting it would "
                    .'silently untag every one of them. Unlink it from its categories instead — '
                    .'the sidebar stops offering it and nothing is lost.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $name = $attribute->name;
        $attribute->delete();

        return $this->successResponse(null, "Filter '{$name}' deleted.");
    }

    /**
     * A unit belongs to a measurement. Keeping one on a list of names leaves
     * "IPS Mbps" waiting to be rendered somewhere.
     *
     * @param  array<string, mixed>  $validated
     */
    private function unitFor(array $validated): ?string
    {
        if ($validated['input_type'] !== Attribute::NUMBER) {
            return null;
        }

        $unit = trim((string) ($validated['unit'] ?? ''));

        return $unit === '' ? null : $unit;
    }

    /**
     * Replace the answer list with the one that arrived.
     *
     * Rows that came back with an id keep it, so a product's tick survives an
     * edit to the label beside it. A row that is simply absent is one the admin
     * deleted — and is refused if any product still carries it, because the
     * cascade would drop those ticks without saying so.
     *
     * @param  array<int, array<string, mixed>>  $values
     *
     * @throws StorefrontException
     */
    private function syncValues(Attribute $attribute, array $values): void
    {
        $keptIds = array_values(array_filter(array_map(
            fn ($v) => isset($v['id']) ? (int) $v['id'] : null,
            $values
        )));

        $doomed = $attribute->values()
            ->when($keptIds !== [], fn ($q) => $q->whereKeyNot($keptIds))
            ->withCount('products')
            ->get();

        $inUse = $doomed->filter(fn (AttributeValue $v) => $v->products_count > 0);

        if ($inUse->isNotEmpty()) {
            $names = $inUse->take(3)->pluck('label')->implode(', ');
            $more = $inUse->count() > 3 ? ' and others' : '';

            throw new StorefrontException(
                "You cannot remove {$names}{$more} — {$inUse->sum('products_count')} product(s) "
                    .'answer this filter that way, and removing it would untag them without saying so. '
                    .'Retag those products first, or rename the answer instead of deleting it.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $doomed->each->delete();

        $isNumber = $attribute->input_type === Attribute::NUMBER;

        foreach (array_values($values) as $position => $value) {
            $label = trim((string) $value['label']);

            $attributes = [
                'label' => $label,
                // Position in the list is the order, so dragging a row is the
                // whole gesture — there is no separate number to keep in step.
                'sort_order' => $value['sort_order'] ?? $position,
                'range_from' => $isNumber ? $this->numberOrNull($value['range_from'] ?? null) : null,
                'range_to' => $isNumber ? $this->numberOrNull($value['range_to'] ?? null) : null,
            ];

            $existing = isset($value['id'])
                ? $attribute->values()->find($value['id'])
                : null;

            if ($existing) {
                // The slug only moves when the label does, so a URL that a
                // shopper has filtered by survives a tidy-up of the wording.
                if ($existing->label !== $label) {
                    $attributes['slug'] = SlugFactory::uniqueWithin(
                        AttributeValue::class,
                        $label,
                        ['attribute_id' => $attribute->id],
                        $existing->id
                    );
                }

                $existing->update($attributes);

                continue;
            }

            $attribute->values()->create([
                ...$attributes,
                'slug' => SlugFactory::uniqueWithin(
                    AttributeValue::class,
                    $label,
                    ['attribute_id' => $attribute->id]
                ),
            ]);
        }
    }

    private function numberOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function taggedCount(Attribute $attribute): int
    {
        return (int) DB::table('attribute_value_product')
            ->whereIn('attribute_value_id', $attribute->values->pluck('id'))
            ->count();
    }

    private function reload(Attribute $attribute): Attribute
    {
        return Attribute::with([
            'values' => fn ($q) => $q->withCount('products'),
            'categories:id,name,slug,parent_id',
            'categories.parent:id,name,parent_id',
            'categories.parent.parent:id,name',
        ])->findOrFail($attribute->id);
    }

    /**
     * A shelf's ancestors, nearest last: "Laptop › Gaming Laptop".
     *
     * Two levels up, which is what the category search returns and as deep as
     * this tree goes before the names start being distinct on their own.
     */
    private function pathFor(Category $category): string
    {
        return collect([
            $category->parent?->parent?->name,
            $category->parent?->name,
        ])->filter()->implode(' › ');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Attribute $attribute): array
    {
        return [
            'id' => $attribute->id,
            'name' => $attribute->name,
            'slug' => $attribute->slug,
            'unit' => $attribute->unit,
            'input_type' => $attribute->input_type,
            'sort_order' => $attribute->sort_order,
            'categories' => $attribute->categories->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                /*
                 * The ancestry, not just the name. The tree has four shelves
                 * called Asus and several called Accessories, so a filter
                 * listed as shown on "Asus" names none of them in particular.
                 */
                'path' => $this->pathFor($c),
            ])->values()->all(),
            'values' => $attribute->values->map(fn (AttributeValue $v) => [
                'id' => $v->id,
                'label' => $v->label,
                'slug' => $v->slug,
                'range_from' => $v->range_from,
                'range_to' => $v->range_to,
                'sort_order' => $v->sort_order,
                'products_count' => $v->products_count ?? 0,
            ])->values()->all(),
        ];
    }
}
