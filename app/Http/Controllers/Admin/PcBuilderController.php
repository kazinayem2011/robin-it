<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PcBuilderSlot;
use App\Support\PcBuilderHealth;
use App\Support\PcBuilderSlots;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the PC Builder is offering, and why.
 *
 * Nothing in the admin has ever said how a part reaches a builder slot. It is
 * the category the product is filed under and its Active tick — not stock, not
 * a flag on the builder, and there is no screen that mentions it. Compatibility
 * is driven by specification names that have to match, and a missing one counts
 * as "unknown" rather than a failure, so a build nobody could check looks
 * exactly like one that passed.
 *
 * The parts themselves are changed here (PcBuilderPartController); which
 * products fill them is still changed on the product or its category, and this
 * explains where to go for that.
 */
class PcBuilderController extends Controller
{
    public function __construct(
        private readonly PcBuilderHealth $health,
        private readonly PcBuilderPartController $partRows,
        private readonly PcBuilderSlots $parts,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/PcBuilder', [
            // What needs doing, then the detail behind a fold. summary() is
            // the dashboard card's, and the page has no use for it.
            'problems' => $this->health->problems(),
            'slots' => $this->health->slots(),
            // The parts themselves, hidden ones included: this page is now
            // where they are added, changed and ordered.
            // Each with what a customer can choose from it and what its
            // products are missing — one table instead of two lists of the
            // same parts.
            'parts' => $this->partsWithHealth(),
            'icons' => PcBuilderSlot::ICONS,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function partsWithHealth(): array
    {
        $health = collect($this->health->slots())->keyBy('id');

        return $this->parts->all(true)
            ->map(function ($r) use ($health) {
                $row = $this->partRows->row($r['slot']);
                $h = $health->get($row['builder_id']);

                return $row + [
                    // Absent when the part is hidden or has nothing to offer.
                    'products' => $h['parts'] ?? 0,
                    'in_stock' => $h['in_stock'] ?? 0,
                    'starved' => $r['slot']->is_required && ($h['parts'] ?? 0) === 0,
                    'needs_specs' => $h['needs_specs'] ?? [],
                    'missing_specs' => $h['missing_specs'] ?? 0,
                    'first_category_id' => $r['categories']->first()?->id,
                ];
            })
            ->values()
            ->all();
    }
}
