<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use Carbon\CarbonInterface;

/**
 * Everything that happened to one order, oldest first, and who did it.
 *
 * Read from the records the shop already keeps — payments, refunds, edits, the
 * stock ledger, serials — plus the status log. Nothing here is written twice:
 * the order window shows what those records say, in one line each.
 *
 * Status moves made before the status log existed have no record of who made
 * them. For those the order's own dates stand in, with "not recorded" as the
 * name rather than a guess.
 */
class OrderActivity
{
    /**
     * @return list<array{at: string, when: string, kind: string, title: string, detail: ?string, by: ?string}>
     */
    public static function for(Order $order): array
    {
        $order->loadMissing([
            'user:id,name',
            'statusChanges',
            'courier:id,name',
            'payments',
            'refunds.processedBy:id,name',
            'edits',
        ]);

        $rows = [];
        $add = function (?CarbonInterface $at, string $kind, string $title, ?string $detail = null, ?string $by = null) use (&$rows) {
            if (! $at) {
                return;
            }

            $rows[] = [
                'at' => $at->toIso8601String(),
                'when' => ShopDate::show($at, 'j M Y, g:i A'),
                'kind' => $kind,
                'title' => $title,
                'detail' => $detail,
                'by' => $by,
            ];
        };

        $money = fn ($n) => '৳'.number_format((float) $n, 0);
        $label = fn (?string $s) => $s ? ucfirst($s) : '—';

        $add(
            $order->created_at,
            'placed',
            'Order placed — '.$money($order->total).($order->payment_method === 'COD' ? ', cash on delivery' : ''),
            null,
            $order->user?->name ?? (($order->shipping_address['name'] ?? null) ? $order->shipping_address['name'].' (guest)' : 'Guest'),
        );

        // Which courier took it, on the line where it went out.
        $carrier = collect([$order->courier?->name, $order->tracking_number])->filter()->implode(' · ') ?: null;

        foreach ($order->statusChanges as $change) {
            $add(
                $change->created_at,
                'status',
                'Status: '.$label($change->from_status).' → '.$label($change->to_status),
                $change->to_status === 'shipped' ? $carrier : null,
                $change->by_name ?? 'the system',
            );
        }

        /*
         * An order older than the status log. Its dates still say when it was
         * dispatched, delivered or taken back; only the name is missing.
         */
        if ($order->statusChanges->isEmpty()) {
            $add($order->dispatched_at, 'status', 'Dispatched', $carrier, 'not recorded');
            $add($order->delivered_at, 'status', 'Delivered', null, 'not recorded');

            if ($order->status === 'returned') {
                $add($order->stock_returned_at ?? $order->updated_at, 'status', 'Marked returned', null, 'not recorded');
            }

            if ($order->status === 'cancelled') {
                $add($order->stock_released_at ?? $order->updated_at, 'status', 'Cancelled', null, 'not recorded');
            }
        }

        foreach ($order->payments as $payment) {
            $add(
                $payment->created_at,
                'payment',
                'Paid: '.$money($payment->amount).' '.strtolower($payment->method_label ?? $payment->method),
                $payment->reference ? 'Ref '.$payment->reference : $payment->note,
                $payment->received_by_name,
            );
        }

        foreach ($order->refunds as $refund) {
            $add(
                $refund->created_at,
                'refund',
                'Refunded: '.$money($refund->amount).($refund->method_label ? ' '.strtolower($refund->method_label) : ''),
                $refund->reason_label ?: $refund->reason,
                $refund->processedBy?->name,
            );
        }

        foreach ($order->edits as $edit) {
            $lines = collect($edit->changes ?? [])
                ->map(fn ($c) => ($c['product'] ?? 'Item').': '.($c['from'] ?? 0).' → '.($c['to'] ?? 0))
                ->implode('; ');

            $add(
                $edit->created_at,
                'edit',
                'Items changed: total '.$money($edit->total_before).' → '.$money($edit->total_after),
                trim($lines.($edit->reason ? ' — '.$edit->reason : '')) ?: null,
                $edit->edited_by_name,
            );
        }

        $movements = StockMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->with(['product:id,name', 'variant:id,name', 'store:id,name'])
            ->get();

        foreach ($movements as $m) {
            $name = ($m->product?->name ?? 'Item').($m->variant ? ' — '.$m->variant->name : '');
            $where = $m->store?->name;
            $n = abs($m->quantity);

            $add(
                $m->created_at,
                'stock',
                $m->quantity < 0
                    ? "Stock: {$n} × {$name} out".($where ? " of {$where}" : '')
                    : "Stock: {$n} × {$name} back".($where ? " to {$where}" : ''),
                StockMovement::LABELS[$m->type] ?? null,
            );
        }

        $serials = ProductSerial::query()
            ->where('order_id', $order->id)
            ->with('product:id,name')
            ->get();

        foreach ($serials as $serial) {
            $add(
                $serial->sold_at ?? $serial->updated_at,
                'serial',
                "Serial {$serial->serial} sent",
                $serial->product?->name,
            );
        }

        // Oldest first; on a tie, the order the list above was built in.
        $keyed = array_map(null, array_keys($rows), $rows);
        usort($keyed, fn ($a, $b) => [$a[1]['at'], $a[0]] <=> [$b[1]['at'], $b[0]]);

        return array_map(fn ($pair) => $pair[1], $keyed);
    }
}
