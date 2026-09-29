<?php

namespace App\Support\Reports;

use App\Models\WarrantyClaim;
use Illuminate\Support\Carbon;

/**
 * Warranty claims over a period: how many, how fast, on what, and what the
 * replacements cost.
 *
 * Claims are counted by the day they were filed. Replacement cost is by the
 * day the unit left the shelf, the same as Stock lost on the profit and loss,
 * which it is part of.
 */
class WarrantyReport
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $from, string $to): array
    {
        $claims = WarrantyClaim::with('unit.product:id,name')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get();

        $ready = $claims->filter(fn (WarrantyClaim $c) => $c->ready_at !== null);
        $replacements = Sold::stockLost($from, $to, 'warranty_replacement');

        return [
            'totals' => [
                'opened' => $claims->count(),
                // A position, not a period: what is on the bench today.
                'open_now' => WarrantyClaim::whereNotIn('status', WarrantyClaim::FINAL)->count(),
                'completed' => $claims->where('status', 'completed')->count(),
                'rejected' => $claims->where('status', 'rejected')->count(),
                'replaced' => $claims->whereNotNull('replacement_serial_id')->count(),
                // Filed to ready to collect: how long a customer is without
                // the thing they paid for.
                'average_days' => $ready->isEmpty() ? null : round($ready->avg(
                    fn (WarrantyClaim $c) => $c->created_at->diffInHours($c->ready_at) / 24
                ), 1),
                'replacement_cost' => $replacements['amount'],
                // Claims on a serial the shop has no record of. Worth seeing:
                // each is one nobody can check, and the fix is recording
                // serials when stock arrives.
                'unknown_units' => $claims->whereNull('product_serial_id')->count(),
            ],

            'by_stage' => collect(WarrantyClaim::LABELS)
                ->map(fn ($label, $status) => [
                    'status' => $status,
                    'label' => $label,
                    'claims' => $claims->where('status', $status)->count(),
                ])
                ->values()
                ->all(),

            /*
             * By the shop's own product where the unit is known, and by what
             * the customer typed where it is not — so a model that keeps
             * failing shows up either way.
             */
            'by_product' => $claims
                ->groupBy(fn (WarrantyClaim $c) => $c->unit?->product?->name ?? $c->product_name)
                ->map(fn ($theirs, $name) => [
                    'name' => $name,
                    'claims' => $theirs->count(),
                    'replaced' => $theirs->whereNotNull('replacement_serial_id')->count(),
                    'rejected' => $theirs->where('status', 'rejected')->count(),
                    'known' => $theirs->whereNotNull('product_serial_id')->isNotEmpty(),
                ])
                ->sortByDesc('claims')
                ->values()
                ->take(50)
                ->all(),

            'rejected' => $claims
                ->where('status', 'rejected')
                ->sortByDesc('created_at')
                ->map(fn (WarrantyClaim $c) => [
                    'claim_number' => $c->claim_number,
                    'product' => $c->product_name,
                    'serial' => $c->serial_number,
                    'why' => $c->diagnostic_notes,
                    'on' => Carbon::parse($c->closed_at ?? $c->updated_at)->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }
}
